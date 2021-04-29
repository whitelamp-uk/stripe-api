<?php

namespace Blotto\Stripe;

use \Stripe\Stripe;
use \Stripe\PaymentIntent;

class PayApi {

    private  $connection;
    public   $constants = [
                 'STRIPE_CODE',
                 'STRIPE_ADMIN_EMAIL',
                 'STRIPE_ADMIN_PHONE',
                 'STRIPE_TERMS',
                 'STRIPE_PRIVACY',
                 'STRIPE_EMAIL',
                 'STRIPE_ERROR_LOG',
                 'STRIPE_CNFM_EM',
                 'STRIPE_CNFM_PH',
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
            $step = null;
            $this->complete ($txn_ref);
            $this->supporter = $this->supporter_add ($txn_ref);
            // Send confirmation email
            if (PAYPAL_CMPLN_EM) {
                $step = 'Confirmation email';
                $this->campaign_monitor ($this->supporter);
            }
            // Send confirmation SMS
            if (PAYPAL_CMPLN_PH) {
                if (!class_exists('\SMS')) {
                    throw new \Exception ('Class \SMS not found');
                    return false;
                }
                $step = 'Confirmation SMS';
                $sms        = new \SMS ();
                // Temporarily
                $message    = print_r ($this->supporter,true);
                $sms->send ($this->supporter['Mobile'],$message,PAYPAL_SMS_FROM);
            }
            return true;
        }
        catch (\Exception $e) {
            $error = "Error for txn=$txn_ref: {$e->getMessage()}";
        }
        error_log ($error);
        mail (
            PAYPAL_EMAIL_ERROR,
            'Stripe sign-up callback error',
            $error
        );
        return false;
    }

    private function campaign_monitor ($ref,$tickets,$first_draw_close,$draws) {
        $cm         = new \CS_REST_Transactional_SmartEmail (
            CAMPAIGN_MONITOR_SMART_EMAIL_ID,
            array ('api_key' => CAMPAIGN_MONITOR_KEY)
        );
        $first      = new \DateTime ($first_draw_close);
        $first->add ('P1D');
        $first      = $first->format ('l jS F Y');
        $name       = str_replace (':','',$_POST['first_name']);
        $name      .= ' ';
        $name      .= str_replace (':','',$_POST['last_name']);
        $message    = array (
            "To"    => $name.' <'.$_POST['email'].'>',
            "Data"  => $this->supporter
        );
        $result     = $cm->send (
            $message,
            'unchanged'
        );
        // error_log ('Campaign Monitor result: '.print_r($result,true));
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

    private function sms_message ( ) {
        return [
            'from' => STRIPE_SMS_FROM,
            'message' => STRIPE_SMS_MESSAGE
        ];
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
        $ccc        = STRIPE_CODE;
        $provider   = STRIPE_CODE;
        $refno      = STRIPE_REFNO_OFFSET + $s['id'];
        $cref       = STRIPE_CODE.'_'.$refno;
        // Insert a supporter, a player and a contact
        try {
            $this->connection->query (
              "
                INSERT INTO `blotto_supporter` SET
                  `created`=DATE('{$s['created']}')
                 ,`signed`=DATE('{$s['created']}')
                 ,`approved`=DATE('{$s['created']}')
                 ,`canvas_code`='$ccc'
                 ,`canvas_agent_ref`='$ccc'
                 ,`canvas_ref`='{$s['id']}'
                 ,`client_ref`='$cref'
              "
            );
            $sid = $this->connection->lastInsertId ();
            $this->connection->query (
              "
                INSERT INTO `blotto_player` SET
                 ,`started`=DATE('{$s['created']}')
                 ,`supporter_id`=$sid
                 ,`client_ref`='$cref'
                 ,`chances`={$s['quantity']}
              "
            );
            $this->connection->query (
              "
                INSERT INTO `blotto_contact` SET
                  `supporter_id`=$sid
                 ,`title`='{$s['title']}'
                 ,`name_first`='{$s['first_name']}'
                 ,`name_last`='{$s['last_name']}'
                 ,`email`='{$s['email']}'
                 ,`mobile`='{$s['mobile']}'
                 ,`telephone`='{$s['telephone']}'
                 ,`address_1`='{$s['address_1']}'
                 ,`address_2`='{$s['address_2']}'
                 ,`address_3`='{$s['address_3']}'
                 ,`town`='{$s['town']}'
                 ,`county`='{$s['county']}'
                 ,`postcode`='{$s['postcode']}'
                 ,`dob`='{$s['dob']}'
                 ,`p0`='{$s['pref_1']}'
                 ,`p1`='{$s['pref_2']}'
                 ,`p2`='{$s['pref_3']}'
                 ,`p3`='{$s['pref_4']}'
              "
            );
            // I guess we have to add tickets here so that they can be emailed/texted
            $tickets = [];
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (121,'SQL insert failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
        return [
            'Mobile'        => $s['first_name'],
            'First_Name'    => $s['first_name'],
            'Reference'     => $cref,
            'Chances'       => $s['quantity'],
            'Tickets'       => explode (',',$tickets),
            'Draws'         => $s['draws'],
            'First_Draw'    => draw_first ($s['created'],STRIPE_CODE)
        ];
    }

}

require_once STRIPE_INIT_FILE;

