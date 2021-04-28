<?php

namespace Blotto\Stripe;

use \Stripe\Stripe;
use \Stripe\PaymentIntent;

class PayApi {

    private  $connection;
    public   $constants = [
                 'STRIPE_CODE',
                 //'STRIPE_DIR_STRIPE',
                 'STRIPE_ADMIN_EMAIL',
                 'STRIPE_ADMIN_PHONE',
                 'STRIPE_TERMS',
                 'STRIPE_PRIVACY',
                 'STRIPE_EMAIL',
                 'STRIPE_ERROR_LOG',
                 'STRIPE_CNFM_EM',
                 'STRIPE_CNFM_PH',
                 'STRIPE_CMPLN_EM',
                 'STRIPE_CMPLN_PH',
                 'STRIPE_VOODOOSMS',
                 //'STRIPE_SMS_FROM',
                 //'STRIPE_SMS_MESSAGE',
                 //'STRIPE_CAMPAIGN_MONITOR'
             ];
    public   $database;
    public   $diagnostic;
    public   $error;
    public   $errorCode = 0;
    private  $from;
    public   $tickets = [];

    private  $txn_ref;

    public function __construct ($connection) {
        $this->connection = $connection;
        $this->setup ();
    }

    public function __destruct ( ) {
    }

    public function button ( ) {
        if ($this->txn_ref) {
            require __DIR__.'/button.php';
        }
    }

    public function callback ( ) {
        try {
            // Do Stripe stuff
        }
        catch (\Exception $e) {
            // Say FOOEY back to Stripe
        }
        $error = null;
        $step = null;
        try {
            // Update stripe_payment - `Paid`=NOW() where txn_ref=...
            // Insert a supporter $this->supporter_add ($txn_ref)
            //     canvas code is STRIPE_CODE
            //     canvas_ref is new insert ID
            //     RefNo == canvas_ref + 100000
            //     Provider = STRIPE_CODE
            //     ClientRef = STRIPE_CODE . Refno
            // Em olrait?
            // Assign tickets by updating blotto_ticket
            try {
                // Say OK back to Stripe
                // Send confirmation email
                if (STRIPE_CMPLN_EM) {
                    $step = 'Confirmation email';
                    $this->campaign_monitor ($supporter_nr,$tickets,$first_draw_close,$draws);
                }
                // Send confirmation SMS
                if (STRIPE_CMPLN_PH) {
                    $step = 'Confirmation SMS';
                    $sms        = new \SMS ();
                    $details    = sms_message ();
                    $sms->send ($_POST['mobile'],$details['message'],$details['from']);
                }
                return true;
            }
            catch (\Exception $e) {
                $error = 'TXN_REF '.$txn_ref.' '.$step.': '.$e->getMessage();
            }
        }
        catch (\mysqli_sql_exception $e) {
            $error = 'TXN_REF '.$txn_ref.' SQL exception: '.$e->getMessage();
        }
        error_log ($error);
        mail (
            STRIPE_EMAIL_ERROR,
            'Stripe sign-up callback error',
            $error
        );
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
            "Data"  => array (
                'First_Name'    => $_POST['first_name'],
                'Reference'     => $ref,
                'Tickets'       => $tickets,
                'First'         => $first,
                'Draws'         => $draws,
            )
        );
        $result     = $cm->send (
            $message,
            'unchanged'
        );
        // error_log ('Campaign Monitor result: '.print_r($result,true));
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
            $supporter = $this->connection->query (
              "SELECT * FROM `stripe_payment` WHERE `txn_ref`='$txn_ref' LIMIT 0,1"
            );
            $supporter = $supporter->fetch_assoc ();
            if (!$supporter) {
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
        $refno      = STRIPE_REFNO_OFFSET + $supporter['id'];
        $cref       = STRIPE_CODE.'_'.$refno;
        // Insert a supporter, a player and a contact
        try {
            $this->connection->query (
              "
                INSERT INTO `blotto_supporter` SET
                  `created`=DATE('{$supporter['created']}')
                 ,`signed`=DATE('{$supporter['created']}')
                 ,`approved`=DATE('{$supporter['created']}')
                 ,`canvas_code`='$ccc'
                 ,`canvas_agent_ref`='$ccc'
                 ,`canvas_ref`='{$supporter['id']}'
                 ,`client_ref`='$cref'
              "
            );
            $sid = $this->connection->lastInsertId ();
            $this->connection->query (
              "
                INSERT INTO `blotto_player` SET
                 ,`started`=DATE('{$supporter['created']}')
                 ,`supporter_id`=$sid
                 ,`client_ref`='$cref'
                 ,`chances`={$supporter['quantity']}
              "
            );
            $this->connection->query (
              "
                INSERT INTO `blotto_contact` SET
                  `supporter_id`=$sid
                 ,`title`='{$supporter['title']}'
                 ,`name_first`='{$supporter['first_name']}'
                 ,`name_last`='{$supporter['last_name']}'
                 ,`email`='{$supporter['email']}'
                 ,`mobile`='{$supporter['mobile']}'
                 ,`telephone`='{$supporter['telephone']}'
                 ,`address_1`='{$supporter['address_1']}'
                 ,`address_2`='{$supporter['address_2']}'
                 ,`address_3`='{$supporter['address_3']}'
                 ,`town`='{$supporter['town']}'
                 ,`county`='{$supporter['county']}'
                 ,`postcode`='{$supporter['postcode']}'
                 ,`dob`='{$supporter['dob']}'
                 ,`p0`='{$supporter['pref_1']}'
                 ,`p1`='{$supporter['pref_2']}'
                 ,`p2`='{$supporter['pref_3']}'
                 ,`p3`='{$supporter['pref_4']}'
              "
            );
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (121,'SQL insert failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
    }

}

require_once STRIPE_INIT_FILE;
//require_once STRIPE_CAMPAIGN_MONITOR;

