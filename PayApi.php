<?php

namespace Blotto\Stripe;

use \Stripe\Stripe;
use \Stripe\PaymentIntent;

class PayApi {

    private  $connection;
    public   $constants = [
                 'STRIPE_CODE',
                 'STRIPE_DIR_STRIPE',
                 'STRIPE_ADMIN_EMAIL',
                 'STRIPE_ADMIN_PHONE',
                 'STRIPE_TERMS' 
                 'STRIPE_PRIVACY' 
                 'STRIPE_EMAIL',
                 'STRIPE_ERROR_LOG',
                 'STRIPE_CNFM_EM',
                 'STRIPE_CNFM_PH',
                 'STRIPE_CMPLN_EM',
                 'STRIPE_CMPLN_PH',
                 'STRIPE_VOODOOSMS',
                 'STRIPE_SMS_FROM',
                 'STRIPE_SMS_MESSAGE',
                 'STRIPE_CAMPAIGN_MONITOR'
             ];
    public   $database;
    public   $diagnostic;
    public   $error;
    public   $errorCode = 0;
    private  $from;
    public   $tickets = [];

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

    private function form_vars ( ) {
        $vars = array (
            'title'      => !STRIPE_DEV_MODE ? '' : 'Mr',
            'first_name' => !STRIPE_DEV_MODE ? '' : 'Mickey',
            'last_name'  => !STRIPE_DEV_MODE ? '' : 'Mouse',
            'dob'        => !STRIPE_DEV_MODE ? '' : '1928-05-15',
            'postcode'   => !STRIPE_DEV_MODE ? '' : 'W1A 1AA',
            'address_1'  => !STRIPE_DEV_MODE ? '' : 'Broadcasting House',
            'address_2'  => !STRIPE_DEV_MODE ? '' : '',
            'address_3'  => !STRIPE_DEV_MODE ? '' : '',
            'town'       => !STRIPE_DEV_MODE ? '' : 'London',
            'county'     => !STRIPE_DEV_MODE ? '' : '',
            'quantity'   => !STRIPE_DEV_MODE ? '' : '1',
            'draws'      => !STRIPE_DEV_MODE ? '' : '1',
            'pref_1'     => !STRIPE_DEV_MODE ? '' : '',
            'pref_2'     => !STRIPE_DEV_MODE ? '' : 'on',
            'pref_3'     => !STRIPE_DEV_MODE ? '' : '',
            'pref_4'     => !STRIPE_DEV_MODE ? '' : '',
            'telephone'  => !STRIPE_DEV_MODE ? '' : '01234567890',
            'mobile'     => !STRIPE_DEV_MODE ? '' : '07890123456',
            'email'      => !STRIPE_DEV_MODE ? '' : 'mm@disney.com',
            'gdpr'       => !STRIPE_DEV_MODE ? '' : 'on',
            'terms'      => !STRIPE_DEV_MODE ? '' : 'on',
            'age'        => !STRIPE_DEV_MODE ? '' : 'on',
            'signed'     => !STRIPE_DEV_MODE ? '' : '',
        );
        foreach ($_POST as $k=>$v) {
            $vars[$k] = $v;
        }
        return $vars;
    }

    function get_address ( ) {
        if (ctype_digit($_POST['address_1'][0])) {
            $firstline          = explode(' ', $_POST['address_1'], 2);
            $house_number       = $firstline[0];
            $address_1          = $firstline[1];
            $address_2          = $_POST['address_2'];
            if ($_POST['address_3']) {
                $address_2     .= ', '.$_POST['address_3'];
            }
            $house_name         = '';
        }
        else {
            $house_name         = $_POST['address_1'];
            $address_1          = $_POST['address_2'];
            $address_2          = $_POST['address_3'];
            $house_number       = '';
        }
        $address_obj            = new \stdClass ();
        $address_obj->city          = $_POST['town'];
        $address_obj->county        = $_POST['county'];
        $address_obj->country       = 'GB';
        $address_obj->postcode      = $_POST['postcode'];
        $address_obj->address_1     = $address_1;
        $address_obj->address_2     = $address_2;
        $address_obj->house_name    = $house_name;
        $address_obj->house_number  = $house_number;
        $address = json_encode ($address_obj);
        return $address;
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

    public function output_signup_form ( ) {
        global $error;
        $v = $this->form_vars ();
        $titles = explode (',',BLOTTO_TITLES_WEB);
        require __DIR__.'/form_signup.php';
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
                throw new \Exception ('Configuration error');
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
        global $error;
        $this->verify_general ();
        if ($_POST['telephone'] && !$this->verify_phone($_POST['telephone'])) {
            $error = 'Telephone number (landline) is not valid';
        }
        elseif ($_POST['mobile'] && !$this->verify_phone($_POST['mobile'],'m')) {
            $error = 'Telephone number (mobile) is not valid';
        }
        elseif ($_POST['email'] && !$this->verify_email($_POST['email'])) {
            $error = 'Email address is not valid';
        }
        else {

            // Insert into stripe_payment leaving especially `Paid` and `Created` as null
            // $this->txn_ref = something unique to go in button
            $this->txn_ref = uniqid();
            Stripe::setApiKey(STRIPE_SECRET_KEY);

            $intent = PaymentIntent::create([
              'amount' => 1099,
              'currency' => 'gbp',
              // Verify your integration in this guide by including this parameter
              'metadata' => ['integration_check' => 'accept_a_payment'],
            ]);

            require __DIR__.'/form_payment.php';

        }
        if ($error) {
            throw new \Exception ($error);
            return false;
        }
    }

    private function sms_message ( ) {
        return [
            'from' => STRIPE_SMS_FROM,
            'message' => STRIPE_SMS_MESSAGE
        ];
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
                  `created`=DATE('{$['created']}')
                 ,`signed`=DATE('{$['created']}')
                 ,`approved`=DATE('{$['created']}')
                 ,`canvas_code`='$ccc'
                 ,`canvas_agent_ref`='$ccc'
                 ,`canvas_ref`='{$['id']}'
                 ,`client_ref`='$cref'
              "
            );
            $sid = $this->connection->lastInsertId ();
            $this->connection->query (
              "
                INSERT INTO `blotto_player` SET
                 ,`started`=DATE('{$['created']}')
                 ,`supporter_id`=$sid
                 ,`client_ref`='$cref'
                 ,`chances`={$['quantity']}
              "
            );
            $this->connection->query (
              "
                INSERT INTO `blotto_contact` SET
                  `supporter_id`=$sid
                 ,`title`='{$['title']}'
                 ,`name_first`='{$['first_name']}'
                 ,`name_last`='{$['last_name']}'
                 ,`email`='{$['email']}'
                 ,`mobile`='{$['mobile']}'
                 ,`telephone`='{$['telephone']}'
                 ,`address_1`='{$['address_1']}'
                 ,`address_2`='{$['address_2']}'
                 ,`address_3`='{$['address_3']}'
                 ,`town`='{$['town']}'
                 ,`county`='{$['county']}'
                 ,`postcode`='{$['postcode']}'
                 ,`dob`='{$['dob']}'
                 ,`p0`='{$['pref_1']}'
                 ,`p1`='{$['pref_2']}'
                 ,`p2`='{$['pref_3']}'
                 ,`p3`='{$['pref_4']}'
              "
            );
        }
        catch (\mysqli_sql_exception $e) {
            $this->error_log (121,'SQL insert failed: '.$e->getMessage());
            throw new \Exception ('SQL error');
            return false;
        }
    }

    private function verify_email ($email) {
        $params = array(
            "username" => STRIPE_D8_USERNAME,
            "password" => STRIPE_D8_PASSWORD,
            "email" => $email,
            "level" => STRIPE_D8_EML_VERIFY_LEVEL,
        );
        $client = new \SoapClient ("https://webservices.data-8.co.uk/EmailValidation.asmx?WSDL");
        $result = $client->IsValid($params);
        if ($result->IsValidResult->Status->Success == false) {
            throw new \Exception ("Error trying to validate email: ".$result->Status->ErrorMessage);
            return false;
        }
        if ($result->IsValidResult->Result=='Invalid') {
            define ( 'STRIPE_GO', 'contact');
            throw new \Exception ("$email is an invalid address");
            return false;
        }
        return true;
    }

/*
    DL:
        IMO all fields should be tested and an array of error messages created
        and returned...
    MP:
        It's a grey area:
            1.  Returning anything other than false tends to imply all is well.
            2.  For me at least, code that *returns* error strings is somewhat
                of a gotcha.
            3.  If the API fails to complete a transaction one might argue
                that this is "fatal" to the API's primary purpose whether or not
                the cause is user input. "Infinite are the arguments of the mages"
        Middle ground position:
            1.  If this class serves the code that collects the user input (which
                it does currently) then it's a fair argument that bad user input
                should not result in a "fatal" exception.
            2.  Functions that need to report errors, instead of returning the
                errors (which is weird), should return false and set the errors
                by reference:

                function do_stuff ($arg1,$arg2,&$user_errors=null) {
                    $user_errors = [];
                    // Use any "usual" arguments passed to do stuff and
                    if ($there_is_an_error) {
                        $user_errors[] = "I don't think you wanted to do that";
                        return false;
                    }
                    // Everything is good
                    return true;
                }

                if ($do_stuff('abc','def',$e)) {
                    // Do more stuff here
                }
                else {
                    print_r ($e);
                }
*/
    public function verify_general ( ) {
        foreach ($_POST as $key => $value) {
            $_POST[$key] = trim($value);
        }
        if (!$_POST['title']) {
            define ( 'STRIPE_GO', 'about');
            throw new \Exception ('Title is required');
            return false;
        }
        if (!$_POST['first_name']) {
            define ( 'STRIPE_GO', 'about');
            throw new \Exception ('First name is required');
            return false;
        }
        if (!$_POST['last_name']) {
            define ( 'STRIPE_GO', 'about');
            throw new \Exception ('Last name is required');
            return false;
        }
        if (!$_POST['dob']) {
            define ( 'STRIPE_GO', 'about');
            throw new \Exception ('Date of birth is required');
            return false;
        }
        $dt             = new \DateTime ($_POST['dob']);
        if (!$dt) {
            define ( 'STRIPE_GO', 'about');
            throw new \Exception ('Date of birth is not valid');
            return false;
        }
        $now        = new \DateTime ();
        $years      = $dt->diff($now)->format ('%r%y');
        if ($years<18) {
            throw new \Exception ('You must be 18 or over to sign up');
            return false;
        }
        if (!$_POST['postcode']) {
            define ( 'STRIPE_GO', 'address');
            throw new \Exception ('Postcode is required');
            return false;
        }
        if (!$_POST['address_1']) {
            define ( 'STRIPE_GO', 'address');
            throw new \Exception ('First line of address is required');
            return false;
        }
        if (!$_POST['town']) {
            define ( 'STRIPE_GO', 'address');
            throw new \Exception ('Town/city is required');
            return false;
        }
        /*if (!$_POST['sort_code']) {
            define ( 'STRIPE_GO', 'bank');
            throw new \Exception ('Bank sort code is required');
            return false;
        }
        if (!$_POST['account_number']) {
            define ( 'STRIPE_GO', 'bank');
            throw new \Exception ('Bank account number is required');
            return false;
        }*/
        if (!array_key_exists('gdpr',$_POST) || !$_POST['gdpr']) {
            define ( 'STRIPE_GO', 'gdpr');
            throw new \Exception ('You must confirm that you have read the GDPR statement');
            return false;
        }
        /*if (!array_key_exists('signed',$_POST) || !$_POST['signed']) {
            define ( 'STRIPE_GO', 'sign');
            throw new \Exception ('You must confirm your direct debit instruction');
            return false;
        }*/
        if (!array_key_exists('terms',$_POST) || !$_POST['terms']) {
            define ( 'STRIPE_GO', 'sign');
            throw new \Exception ('You must agree to terms & conditions and the privacy policy');
            return false;
        }
        if (!array_key_exists('age',$_POST) || !$_POST['age']) {
            define ( 'STRIPE_GO', 'sign');
            throw new \Exception ('You must be aged 18 or over to signup');
            return false;
        }
        return true;
    }

    function verify_phone ($number, $type='l') {
        $params = array(
            "username" => STRIPE_D8_USERNAME,
            "password" => STRIPE_D8_PASSWORD,
            "telephoneNumber" => $number,
            "defaultCountry" => 'GB',
        );
        $params['options']['Option'][] =  array("Name" => "UseMobileValidation", "Value" => false);
        $params['options']['Option'][] =  array("Name" => "UseLineValidation", "Value" => false);
        $client = new \SoapClient ("https://webservices.data-8.co.uk/InternationalTelephoneValidation.asmx?WSDL");
        $result = $client->IsValid($params);
        if ($result->IsValidResult->Status->Success == false) {
            throw new \Exception ("Error trying to validate phone number: ".$result->Status->ErrorMessage);
            return false;
        }
        if ($result->IsValidResult->Result->ValidationResult=='Invalid') {
            define ( 'STRIPE_GO', 'contact');
            throw new \Exception ("$number is not a valid phone number");
            return false;
        }
        elseif ($type == 'm' && $result->IsValidResult->Result->NumberType!='Mobile') {
            define ( 'STRIPE_GO', 'contact');
            throw new \Exception ("$number is not a valid mobile phone number");
            return false;
        }
        return true;
    }

}

