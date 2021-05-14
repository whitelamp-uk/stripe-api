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
                 'STRIPE_DESCRIPTION',
                 'STRIPE_ERROR_LOG',
                 'STRIPE_REFNO_OFFSET',
                 'STRIPE_SECRET_KEY',
                 'STRIPE_PUBLIC_KEY',
                 'STRIPE_DEV_MODE',
                 'STRIPE_TABLE_MANDATE',
                 'STRIPE_TABLE_COLLECTION',
                 'STRIPE_CALLBACK_IPS_URL',
                 'STRIPE_CALLBACK_IPS_TO',
                 'STRIPE_WHSEC'
             ];
    public   $database;
    public   $diagnostic;
    public   $error;
    public   $errorCode = 0;
    private  $from;
    private  $org;
    public   $supporter = [];

    private  $txn_ref;

    public function __construct ($connection,$org=null) {
        $this->connection   = $connection;
        $this->org          = $org;
        $this->setup ();
    }

    public function __destruct ( ) {
    }

    public function callback (&$responded) {
        $responded          = false;
        $error              = null;
        $txn_ref            = null;
        try {
            $step           = 0;
 /*
https://stripe.com/docs/webhooks/signatures has some code
If using signatures I don't think we need to check IPs
           $ips            = $this->callback_valid_ips ();
            if (!STRIPE_DEV_MODE && !in_array($_SERVER['REMOTE_ADDR'],$ips)) {
                throw new \Exception ('Unauthorised callback request from '.$_SERVER['REMOTE_ADDR']);
            }
*/
// Why is this in the local namespace? Dare say it works but I am confused...
            Stripe::setApiKey (STRIPE_SECRET_KEY);
            $postdata       = file_get_contents ('php://input');
            $sig_header     = $_SERVER['HTTP_STRIPE_SIGNATURE'];
            $event          = null;
            try {
                $event      = \Stripe\Webhook::constructEvent (
                    $postdata,
                    $sig_header,
                    STRIPE_WHSEC
                );
            }
            catch (\UnexpectedValueException $e) {
                // Invalid payload
                http_response_code (400);
                exit ();
            }
// See above - why is this is in the Stripe namespace?
            catch (\Stripe\Exception\SignatureVerificationException $e) {
                // Invalid signature
                http_response_code (400);
                exit ();
            }
            if (!is_object($event) || !isset($event->data->object->metadata->payment_id)) {
                error_log (var_export($event,true));
                error_log (var_export($event->data,true));
                error_log (var_export($event->data,true));
                throw new \Exception ('Posted data is not valid');
            }
            $step           = 1;
            $payment_id     = $this->complete ($event);
            // The payment (or lack thereof) is now recorded at this end
            http_response_code (200);
            $responded      = true;
            echo "Transaction completed id={$event->data->object->metadata->payment_id}, type={$event->type}\n";
            if (!$payment_id) {
                return false;
            }
            echo "Payment received\n";
            $step           = 2;
            $this->supporter = $this->supporter_add ($payment_id);
            if (STRIPE_CMPLN_EML) {
                $step   = 3;
                $result = campaign_monitor (
                    $this->org['signup_cm_key'],
                    $this->org['signup_cm_id'],
                    $this->supporter['To'],
                    $this->supporter
                );
                $ok     = $result->http_status_code == 200;
                if (!$ok) {
                    throw new \Exception (print_r($result,true));
                }
            }
            if (STRIPE_CMPLN_MOB) {
                $step   = 4;
                $sms_msg = $this->org['signup_sms_message'];
                foreach ($this->supporter as $k=>$v) {
                    $sms_msg = str_replace ("{{".$k."}}",$v,$sms_msg);
                }
                sms ($this->supporter['Mobile'],$sms_msg,STRIPE_SMS_FROM);
            }
            return true;
        }
        catch (\Exception $e) {
            error_log ($e->getMessage());
            throw new \Exception ("stripe payment_id=$payment_id, step=$step: {$e->getMessage()}");
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

    private function complete ($event) {
        if (!in_array($event->type,['charge.succeeded','charge.failed'])) {
            error_log (print_r($event,true));
            throw new \Exception ('Unrecognised Stripe event type');
            return false;
        }
        $payment_id             = $event->data->object->metadata->payment_id;
        $failure_code           = '';
        $failure_message        = '';
        if ($event->type=='charge.failed') {
            $failure_code       = $event->data->object->failure_code;
            $failure_message    = $event->data->object->failure_message;
        }
        try {
            $this->connection->query (
              "
                UPDATE `stripe_payment`
                SET
                  `callback_at`=NOW()
                 ,`refno`={$this->refno($payment_id)}
                 ,`cref`='{$this->cref($payment_id)}'
                 ,`failure_code`='{$failure_code}'
                 ,`failure_message`='{$failure_message}'
                WHERE `id`='$payment_id'
                LIMIT 1
              "
            );
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (122,'SQL update failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
        if ($event->type=='charge.failed') {
            return false;
        }
        return $payment_id;
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
            // Create the table if not exists
            //$this->execute (__DIR__.'/create_payment_table.sql');
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

    public function start (&$e) {
        Stripe::setApiKey (STRIPE_SECRET_KEY);
        $v = www_signup_vars ();
        foreach ($v as $key => $val) {
            $v[$key] = $this->connection->real_escape_string ($val);
        }
        $amount = intval($v['quantity']) * intval($v['draws']) * BLOTTO_TICKET_PRICE;
        $pounds_amount = number_format ($amount/100,2,'.','');
        $sql = "INSERT INTO `stripe_payment` SET
          `quantity` = '{$v['quantity']}',
          `draws` = '{$v['draws']}',
          `amount` = '{$pounds_amount}',
          `title` = '{$v['title']}',
          `name_first` = '{$v['name_first']}',
          `name_last` = '{$v['name_last']}',
          `dob` = '{$v['dob']}',
          `email` = '{$v['email']}',
          `mobile` = '{$v['mobile']}',
          `telephone` = '{$v['telephone']}',
          `postcode` = '{$v['postcode']}',
          `address_1` = '{$v['address_1']}',
          `address_2` = '{$v['address_2']}',
          `address_3` = '{$v['address_3']}',
          `town` = '{$v['town']}',
          `county` = '{$v['county']}',
          `gdpr` = '{$v['gdpr']}',
          `terms` = '{$v['terms']}',
          `pref_1` = '{$v['pref_1']}',
          `pref_2` = '{$v['pref_2']}',
          `pref_3` = '{$v['pref_3']}',
          `pref_4` = '{$v['pref_4']}'
        ";
        try {
            $this->connection->query ($sql);
            $newid = $this->connection->insert_id;
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (122,'SQL insert failed: '.$e->getMessage());
            $e[] = 'Sorry something went wrong - please try later';
            return;
        }
        $intent = PaymentIntent::create([
          'amount' => $amount,
          'currency' => 'gbp',
          'description' => STRIPE_DESCRIPTION,
          // DL: The docs seem to say metadata is optional and arbitrary; poss required when doing development...
          // Verify your integration in this guide by including this parameter
          'metadata' => ['integration_check' => 'accept_a_payment', 'payment_id' => $newid],
        ]);
// TODO
$this->org['signup_done_message'];
        require __DIR__.'/form.php';
    }

    private function supporter_add ($payment_id) {
        try {
            $s = $this->connection->query (
              "
                SELECT
                  `p`.*
                 ,`p`.`created` AS `first_draw_close`
                 ,drawOnOrAfter(`p`.`created`) AS `draw_first`
                FROM `stripe_payment` AS `p`
                WHERE `p`.`id`='$payment_id'
                LIMIT 0,1
              "
            );
            $s = $s->fetch_assoc ();
            if (!$s) {
                throw new \Exception ("stripe_payment id '$payment_id' was not found");
            }
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (122,'SQL select failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
        // Insert a supporter, a player and a contact
        signup ($s,STRIPE_CODE,$s['cref'],$s['first_draw_close']);
        // Add tickets here so that they can be emailed/texted
        $tickets            = tickets (STRIPE_CODE,$s['refno'],$s['cref'],$s['quantity']);
        $draw_first         = new \DateTime ($s['draw_first']);
        return [
            'To'            => $s['name_first'].' '.$s['name_last'].' <'.$s['email'].'>',
            'Title'         => $s['title'],
            'Name'          => $s['name_first'].' '.$s['name_last'],
            'Email'         => $s['email'],
            'Mobile'        => $s['mobile'],
            'First_Name'    => $s['name_first'],
            'Last_Name'     => $s['name_last'],
            'Reference'     => $s['cref'],
            'Chances'       => $s['quantity'],
            'Tickets'       => implode (',',$tickets),
            'Draws'         => $s['draws'],
            'First_Draw'    => $draw_first->format ('l jS F Y')
        ];
    }

}

require_once STRIPE_INIT_FILE;

