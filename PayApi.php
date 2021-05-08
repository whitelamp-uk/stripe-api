<?php

namespace Blotto\Stripe;

use \Stripe\Stripe;
use \Stripe\PaymentIntent;

class PayApi {

    private  $connection;
    public   $constants = [
                 'STRIPE_CODE',
                 'STRIPE_CMPLN_MOB',
                 'STRIPE_CMPLN_EML',
                 'STRIPE_CMPLN_EML_CM_ID',
                 'STRIPE_ERROR_LOG',
                 'STRIPE_REFNO_OFFSET',
                 'STRIPE_SECRET_KEY',
                 'STRIPE_PUBLIC_KEY',
                 'STRIPE_DEV_MODE',
                 'STRIPE_TABLE_MANDATE',
                 'STRIPE_TABLE_COLLECTION',
                 'STRIPE_CALLBACK_IPS_URL',
                 'STRIPE_CALLBACK_IPS_TO'
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

    public function callback ($sms_msg,&$responded) {
        $responded          = false;
        $error              = null;
        $txn_ref            = null;
        try {
            $step           = 0;
            $ips            = $this->callback_valid_ips ();
            if (!STRIPE_DEV_MODE && !in_array($_SERVER['REMOTE_ADDR'],$ips)) {
                throw new \Exception ('Unauthorised callback request from '.$_SERVER['REMOTE_ADDR']);
            }
            // Data is posted JSON
            $request        = json_decode (trim(file_get_contents('php://input')));
            if (!is_object($request) || !property_exists($request,'txn_ref')) {
                throw new \Exception ('Posted data is not valid');
            }
            $txn_ref        = $request->txn_ref;
            $step = 1;
            $this->complete ($txn_ref);
            // The payment is now recorded at this end
            http_response_code (200);
            $responded      = true;
            echo "Transaction completed\n";
            $step           = 2;
            $this->supporter = $this->supporter_add ($request->txn_ref);
            if (STRIPE_CMPLN_EML) {
                $step       = 3;
                campaign_monitor (
                    STRIPE_CMPLN_EML_CM_ID,
                    $this->supporter['To'],
                    $this->supporter
                );
            }
            if (STRIPE_CMPLN_MOB) {
                $step       = 4;
                foreach ($this->supporter as $k=>$v) {
                    $sms_msg = str_replace ("{{".$k."}}",$v,$sms_msg);
                }
                sms ($this->supporter['Mobile'],$sms_msg,STRIPE_SMS_FROM);
            }
            return true;
        }
        catch (\Exception $e) {
            error_log ($e->getMessage());
            throw new \Exception ("txn=$txn_ref, step=$step: {$e->getMessage()}");
            return false;
        }
    }

    private function callback_valid_ips ( ) {
        $c      = curl_init (STRIPE_CALLBACK_IPS_URL);
        if (!$c) {
            throw new \Exception ('Failed to curl_init("'.STRIPE_CALLBACK_IPS_URL.'")');
            return false;
        }
        $s      = curl_setopt_array (
            $c,
            array (
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_VERBOSE         => false,
                CURLOPT_NOPROGRESS      => true,
                CURLOPT_FRESH_CONNECT   => true,
                CURLOPT_CONNECTTIMEOUT  => STRIPE_CALLBACK_IPS_TO
            )
        );
        if (!$s) {
            throw new \Exception ('Failed to curl_setopt_array()');
            return false;
        }
        $ips    = curl_exec ($c);
        if ($ips===false) {
            throw new \Exception ('Error: '.curl_error($c));
            return false;
        }
        $ips    = json_decode ($ips);
        if (!is_object($ips) || !property_exists($ips,'WEBHOOKS') || !is_array($ips->WEBHOOKS)) {
            throw new \Exception ('Error: Stripe response was broken');
            return false;
        }
        return $ips->WEBHOOKS;
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
        $sql                = $this->sql_instantiate ($sql);
        echo $sql;
        try {
            $this->connection->query ($sql);
            tee ("Output {$this->connection->affected_rows} collections\n");
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
        $sql                = $this->sql_instantiate ($sql);
        echo $sql;
        try {
            $this->connection->query ($sql);
            tee ("Output {$this->connection->affected_rows} mandates\n");
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
            $this->error_log (117,'SQL select failed: '.$e->getMessage());
            throw new \Exception ('SQL database error');
            return false;
        }
    }

    private function sql_instantiate ($sql) {
        $sql                = str_replace ('{{STRIPE_FROM}}',$this->from,$sql);
        $sql                = str_replace ('{{STRIPE_CODE}}',STRIPE_CODE,$sql);
        return $sql;
    }

    public function start ( ) {
        // $this->txn_ref = something unique to go in button
        $this->txn_ref = uniqid ();
        Stripe::setApiKey(STRIPE_SECRET_KEY);
        $v = www_signup_vars ();
        // Insert into stripe_payment leaving especially `Paid` and `Created` as null

        $amount = $v['quantity'] * $v['draws'] * BLOTTO_TICKET_PRICE;
        $intent = PaymentIntent::create([
          'amount' => $amount,
          'currency' => 'gbp',
          'description' => STRIPE_DESCRIPTION,
          // DL: The docs seem to say metadata is optional and arbitrary; poss required when doing development...
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
        // Insert a supporter, a player and a contact
        $cref               = $this->cref ($s['id']);
        signup ($s,STRIPE_CODE,$cref);
        // Add tickets here so that they can be emailed/texted
        $tickets            = tickets (STRIPE_CODE,$this->refno($s['id']),$cref,$s['chances']);
        $draw_first         = new \DateTime (draw_first($s['created'],STRIPE_CODE));
        $draw_first->add ('P1D');
        return [
            'To'            => $s['first_name'].' '.$s['last_name'].' <'.$s['email'].'>',
            'Title'         => $s['title'],
            'Name'          => $s['first_name'].' '.$s['last_name'],
            'Email'         => $s['email'],
            'Mobile'        => $s['first_name'],
            'First_Name'    => $s['first_name'],
            'Last_Name'     => $s['last_name'],
            'Reference'     => $cref,
            'Chances'       => $s['quantity'],
            'Tickets'       => implode (',',$tickets),
            'Draws'         => $s['draws'],
            'First_Draw'    => $draw_first->format ('l jS F Y')
        ];
    }

}

require_once STRIPE_INIT_FILE;

