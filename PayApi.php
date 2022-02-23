<?php

namespace Blotto\Stripe;

/*
https://www.php.net/manual/en/language.namespaces.importing.php
"the leading backslash is unnecessary and not recommended, as import names must
be fully qualified, and are not processed relative to the current namespace"
use \Stripe\Stripe;
use \Stripe\PaymentIntent;
*/
use Stripe\Stripe;
use Stripe\PaymentIntent;

class PayApi {

    private  $connection;
    public   $constants = [
                 'STRIPE_CODE',
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
    public   $today;

    private  $txn_ref;

    public function __construct ($connection,$org=null) {
        // TODO: what about cut-offs and BST?
        $this->today        = date ('Y-m-d');
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
            Stripe::setApiKey (STRIPE_SECRET_KEY);
            $postdata       = file_get_contents ('php://input');
            $sig_header     = $_SERVER['HTTP_STRIPE_SIGNATURE'];
            $event          = null;
            try {
// Above: use Stripe\Webhook;
// Here: $event = Webhook::constructEvent
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
// Above: use Stripe\Exception\SignatureVerificationException;
// Here: catch (SignatureVerificationException $e)
            catch (\Stripe\Exception\SignatureVerificationException $e) {
                // Invalid signature
                http_response_code (400);
                exit ();
            }
            if (!is_object($event) || !isset($event->data->object->metadata->payment_id) || !isset($event->data->object->metadata->product_name)) {
                error_log (var_export($event,true));
                throw new \Exception ('Posted data is not valid');
            }
            if ($event->data->object->metadata->product_name != STRIPE_PRODUCT_NAME) {
                error_log (var_export($event,true));
                throw new \Exception ('Incorrect Product Name');
                return false;
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
            echo "    Adding supporter for payment_id=$payment_id\n";
            $this->supporter = $this->supporter_add ($payment_id);
            echo "    Supporter added = ";
            print_r ($this->supporter);
            if ($this->org['signup_paid_email']>0) {
                $step   = 3;
                $result = campaign_monitor (
                    $this->org['signup_cm_key'],
                    $this->org['signup_cm_id'],
                    $this->supporter['To'],
                    $this->supporter
                );
                $ok     = in_array ($result->http_status_code,[200,201,202]);
                if (!$ok) {
                    throw new \Exception (print_r($result,true));
                }
            }
            if ($this->org['signup_paid_sms']>0) {
                $step   = 4;
                $sms_msg = $this->org['signup_sms_message'];
                foreach ($this->supporter as $k=>$v) {
                    $sms_msg = str_replace ("{{".$k."}}",$v,$sms_msg);
                }
                sms ($this->org,$this->supporter['Mobile'],$sms_msg,$diagnostic);
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
            error_log ("Stripe charge failed $failure_code $failure_message");
        }

        try {
            $failure_code    = $this->connection->real_escape_string ($failure_code);
            $failure_message = $this->connection->real_escape_string ($failure_message);
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
            $this->error_log (127,'SQL update failed: '.$e->getMessage());
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
        $sql = $this->sql_instantiate (file_get_contents($sql_file));
        try {
            $result = $this->connection->query ($sql);
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (126,'SQL execute failed: '.$e->getMessage());
            throw new \Exception ('SQL execution error');
            return false;
        }
        return $result;
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
            $this->error_log (125,'SQL insert failed: '.$e->getMessage());
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
            $this->error_log (124,'SQL insert failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
    }

    private function refno ($id) {
        return STRIPE_REFNO_OFFSET + $id;
    }

    private function setup ( ) {
echo "DINGALING\n";
        foreach ($this->constants as $c) {
            if (!defined($c)) {
                $this->error_log (123,"Configuration error $c not defined");
                throw new \Exception ("Configuration error $c not defined");
                return false;
            }
        }
        $sql                = "SELECT DATABASE() AS `db`";
        try {
            $db             = $this->connection->query ($sql);
            $db             = $db->fetch_assoc ();
            $this->database = $db['db'];
            // Create the table if not exists
            $this->execute (__DIR__.'/create_payment.sql');
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (122,'SQL select failed: '.$e->getMessage());
            throw new \Exception ('SQL database error');
            return false;
        }
    }

    private function sql_instantiate ($sql) {
        $sql                = str_replace ('{{STRIPE_FROM}}',$this->from,$sql);
        $sql                = str_replace ('{{STRIPE_CODE}}',STRIPE_CODE,$sql);
        return $sql;
    }

    public function start (&$err) {
        Stripe::setApiKey (STRIPE_SECRET_KEY);
        $v = www_signup_vars ();
        $today = date ('Y-m-d');
        if ($v['collection_date']) {
            $dt = new \DateTime ();
            $dt->sub (new \DateInterval('P'.BLOTTO_INSURE_DAYS.'D'));
            $dt = $dt->format ('Y-m-d');
            if ($dt<$today) {
                $v['collection_date'] = $today;
            }
            else {
                $v['collection_date'] = $dt;
            }
        }
        else {
            $v['collection_date'] = $today;
        }
        foreach ($v as $key => $val) {
            if (preg_match('<^pref_>',$key)) {
                $v[$key] = yes_or_no ($val,'Y','N');
                continue;
            }
            $v[$key] = $this->connection->real_escape_string ($val);
        }
        $amount = intval($v['quantity']) * intval($v['draws']) * BLOTTO_TICKET_PRICE;
        $pounds_amount = number_format ($amount/100,2,'.','');
        $sql = "
          INSERT INTO `stripe_payment`
          SET
            `collection_date`='{$v['collection_date']}'
           ,`quantity`='{$v['quantity']}'
           ,`draws`='{$v['draws']}'
           ,`amount`='{$pounds_amount}'
           ,`title`='{$v['title']}'
           ,`name_first`='{$v['name_first']}'
           ,`name_last`='{$v['name_last']}'
           ,`dob`='{$v['dob']}'
           ,`email`='{$v['email']}'
           ,`mobile`='{$v['mobile']}'
           ,`telephone`='{$v['telephone']}'
           ,`postcode`='{$v['postcode']}'
           ,`address_1`='{$v['address_1']}'
           ,`address_2`='{$v['address_2']}'
           ,`address_3`='{$v['address_3']}'
           ,`town`='{$v['town']}'
           ,`county`='{$v['county']}'
           ,`gdpr`='{$v['gdpr']}'
           ,`terms`='{$v['terms']}'
           ,`pref_email`='{$v['pref_email']}'
           ,`pref_sms`='{$v['pref_sms']}'
           ,`pref_post`='{$v['pref_post']}'
           ,`pref_phone`='{$v['pref_phone']}'
          ;
        ";
        try {
            $this->connection->query ($sql);
            $newid = $this->connection->insert_id;
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (121,'SQL insert failed: '.$e->getMessage());
            $err[] = 'Sorry something went wrong - please try later';
            return;
        }
        $intent = PaymentIntent::create([
          'amount' => $amount,
          'currency' => 'gbp',
          'description' => STRIPE_DESCRIPTION,
          'metadata' => ['payment_id' => $newid, 'product_name' => STRIPE_PRODUCT_NAME],
        ]);
        require __DIR__.'/form.php';
    }

    private function supporter_add ($payment_id) {
        try {
            $s = $this->connection->query (
              "
                SELECT
                  *
                FROM `stripe_payment`
                WHERE `id`='$payment_id'
                LIMIT 0,1
              "
            );
            $s = $s->fetch_assoc ();
            if (!$s) {
                $this->error_log (120,"stripe_payment id '$payment_id' was not found");
                throw new \Exception ("stripe_payment id '$payment_id' was not found");
                return false;
            }
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (119,'SQL select failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
        // Get first draw dates
        if ($s['collection_date']) {
            $draw_first     = new \DateTime (draw_first_asap($s['collection_date']));
        }
        else {
            $draw_first     = new \DateTime (draw_first_asap($this->today));
        }
        $draw_closed        = $draw_first->format ('Y-m-d');
        // Insert a supporter, a player and a contact
        echo "    Running signup() for '{$s['cref']}'\n";
        signup ($this->org,$s,STRIPE_CODE,$s['cref'],$draw_closed);
        // Add tickets
        echo "    Adding tickets for '{$s['cref']}'\n";
        $tickets            = tickets (STRIPE_CODE,$s['refno'],$s['cref'],$s['quantity']);
        // Return "rich text" data
        try {
            $d = $this->connection->query (
              "SELECT drawOnOrAfter('$draw_closed') AS `draw_date`;"
            );
            $d = $d->fetch_assoc ();
            if (!$d) {
                $this->error_log (118,'SQL failed: '.$e->getMessage());
                throw new \Exception ("SQL function could not be run");
                return false;
            }
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (117,'SQL select failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
        $draw_date          = new \Datetime ($d['draw_date']);
        return [
            'To'                => $s['name_first'].' '.$s['name_last'].' <'.$s['email'].'>',
            'Title'             => $s['title'],
            'Name'              => $s['name_first'].' '.$s['name_last'],
            'Email'             => $s['email'],
            'Mobile'            => $s['mobile'],
            'First_Name'        => $s['name_first'],
            'Last_Name'         => $s['name_last'],
            'Reference'         => $s['cref'],
            'Chances'           => $s['quantity'],
            'Tickets'           => implode (',',$tickets),
            'Draws'             => $s['draws'],
            'First_Draw_Closed' => $draw_first->format ('l jS F Y'),
            'First_Draw_Day'    => $draw_date->format ('l jS F Y'),
            'First_Draw'        => $draw_date->format ('l jS F Y')
        ];
    }

}

require_once STRIPE_INIT_FILE;

