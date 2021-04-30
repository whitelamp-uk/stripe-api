<?php

namespace Blotto\Stripe;

use \Stripe\Stripe;
use \Stripe\PaymentIntent;

class PayApi {

    private  $connection;
    public   $constants = [
                 'STRIPE_CODE',
                 'STRIPE_INIT_FILE',
                 'STRIPE_ADMIN_EMAIL',
                 'STRIPE_ADMIN_PHONE',
                 'STRIPE_TERMS',
                 'STRIPE_PRIVACY',
                 'STRIPE_EMAIL',
                 'STRIPE_CMPLN_EML_CM_ID',
                 'STRIPE_CMPLN_EML',
                 'STRIPE_CMPLN_MOB',
                 'STRIPE_ERROR_LOG',
                 'STRIPE_REFNO_OFFSET'
             ];
    public   $database;
    public   $diagnostic;
    public   $error;
    public   $errorCode = 0;
    private  $from;
    public   $supporter = [];

    private  $txn_ref;

    public function __construct ($connection) {
        $this->connection = $connection;
        $this->setup ();
    }

    public function __destruct ( ) {
    }

    public function callback ( ) {
        try {
            $error = null;
            $step = 1;
            $this->complete ($txn_ref);
            $step = 2;
            $this->supporter = $this->supporter_add ($txn_ref);
            if (STRIPE_CMPLN_EML) {
                $step = 3;
                campaign_monitor (STRIPE_CMPLN_EML_CM_ID,$this->supporter);
            }
            if (STRIPE_CMPLN_MOB) {
                $step = 4;
                // TODO: we need to build a proper message
                $message    = print_r ($this->supporter,true);
                sms ($this->supporter['Mobile'],$message,STRIPE_SMS_FROM);
            }
            return true;
        }
        catch (\Exception $e) {
            $error = "Error for txn=$txn_ref, step=$step: {$e->getMessage()}";
        }
        error_log ($error);
        mail (
            STRIPE_EMAIL_ERROR,
            'Stripe sign-up callback error',
            $error
        );
        return false;
    }

    private function complete ($txn_ref) {
        try {
            $this->connection->query (
                "UPDATE `stripe_payment` SET `paid`=NOW() WHERE `txn_ref`='$txn_ref' LIMIT 1"
            );
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (122,'SQL select failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
    }

    private function cref ($id) {
        return STRIPE_CODE.'_'.$this->refno($id);
    }

    private function error_log ($code,$message) {
        $this->errorCode    = $code;
        $this->error        = $message;
        if (!defined('STRIPE_ERROR_LOG') || !STRIPE_ERROR_LOG) {
            return;
        }
        error_log ($code.' '.$message);
    }

    private function execute ($sql_file) {
        echo file_get_contents ($sql_file);
        exec (
            'mariadb '.escapeshellarg($this->database).' < '.escapeshellarg($sql_file),
            $output,
            $status
        );
        if ($status>0) {
            $this->error_log (127,$sql_file.' '.implode(' ',$output));
            throw new \Exception ("SQL file '$sql_file' execution error");
            return false;
        }
        return $output;
    }

    public function import ($from) {
        $from               = new \DateTime ($from);
        $this->from         = $from->format ('Y-m-d');
        $this->execute (__DIR__.'/create_payment.sql');
        $this->output_mandates ();
        $this->output_collections ();
    }

    private function output_collections ( ) {
        $sql                = "INSERT INTO `".STRIPE_TABLE_COLLECTION."`\n";
        $sql               .= file_get_contents (__DIR__.'/select_collection.sql');
        $sql                = str_replace ('{{STRIPE_FROM}}',$this->from,$sql);
        echo $sql;
        try {
            $this->connection->query ($sql);
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (126,'SQL insert failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
    }

    private function output_mandates ( ) {
        $sql                = "INSERT INTO `".STRIPE_TABLE_MANDATE."`\n";
        $sql               .= file_get_contents (__DIR__.'/select_mandate.sql');
        echo $sql;
        try {
            $this->connection->query ($sql);
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (125,'SQL insert failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
    }

    private function refno ($id) {
        return STRIPE_REFNO_OFFSET + $id;
    }

    private function setup ( ) {
        foreach ($this->constants as $c) {
            if (!defined($c)) {
                $this->error_log (124,"$c not defined");
                throw new \Exception ('Configuration error $c not defined');
                return false;
            }
        }
        $sql                = "SELECT DATABASE() AS `db`";
        try {
            $db             = $this->connection->query ($sql);
            $db             = $db->fetch_assoc ();
            $this->database = $db['db'];
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (123,'SQL select failed: '.$e->getMessage());
            throw new \Exception ('SQL database error');
            return false;
        }
    }

    public function start ( ) {
        // Insert into stripe_payment leaving especially `Paid` and `Created` as null
        // $this->txn_ref = something unique to go in button
        $this->txn_ref = uniqid ();
        Stripe::setApiKey(STRIPE_SECRET_KEY);
        $intent = PaymentIntent::create([
          'amount' => 1099,
          'currency' => 'gbp',
          // Verify your integration in this guide by including this parameter
          'metadata' => ['integration_check' => 'accept_a_payment'],
        ]);
        require __DIR__.'/form.php';
    }

    private function supporter_add ($txn_ref) {
        try {
            $s = $this->connection->query (
              "SELECT * FROM `stripe_payment` WHERE `txn_ref`='$txn_ref' LIMIT 0,1"
            );
            $s = $s->fetch_assoc ();
            if (!$s) {
                throw new \Exception ("Transaction reference '$txn_ref' was not identified");
            }
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (122,'SQL select failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
        try {
            // Insert a supporter, a player and a contact
            $cref = $this->cref ($s['id']);
            signup ($s,STRIPE_CODE,$cref);
            // Add tickets here so that they can be emailed/texted
            $tickets = tickets (STRIPE_CODE,$this->refno($s['id']),$cref,$s['chances']);
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (121,'SQL insert failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
        return [
            'Email'         => $s['email'],
            'Mobile'        => $s['first_name'],
            'First_Name'    => $s['first_name'],
            'Last_Name'     => $s['last_name'],
            'Reference'     => $cref,
            'Chances'       => $s['quantity'],
            'Tickets'       => implode (',',$tickets),
            'Draws'         => $s['draws'],
            'First_Draw'    => draw_first ($s['created'],STRIPE_CODE)
        ];
    }

}

require_once STRIPE_INIT_FILE;

