<?php

class PayApi {

    private  $connection;
    public   $constants = [
                 'STRIPE_PROVIDER',
                 'STRIPE_TABLE_MANDATE',
                 'STRIPE_TABLE_COLLECTION',
                 'STRIPE_EMAIL',
                 'STRIPE_ERROR_LOG',
                 'STRIPE_CNFM_EM',
                 'STRIPE_CNFM_PH',
                 'STRIPE_CCC',
                 'STRIPE_CMPLN_EM',
                 'STRIPE_CMPLN_PH'
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
            // Update stripe_payment - `Paid`=NOW(),`Created`=CURDATE()
            // Insert a supporter, a player and a contact
            //     canvas code is STRIPE_CCC
            //     canvas_ref is new insert ID
            //     RefNo == canvas_ref + 100000
            //     Provider = STRIPE_PROVIDER
            //     ClientRef = STRIPE_PROVIDER.Refno
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
                    $sms        = new SMS ();
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
        $cm         = new CS_REST_Transactional_SmartEmail (
            CAMPAIGN_MONITOR_SMART_EMAIL_ID,
            array ('api_key' => CAMPAIGN_MONITOR_KEY)
        );
        $first      = new DateTime ($first_draw_close);
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
            'title' => '',
            'first_name' => '',
            'last_name' => '',
            'dob' => '',
            'postcode' => '',
            'address_1' => '',
            'address_2' => '',
            'address_3' => '',
            'town' => '',
            'county' => '',
            'quantity' => '',
            'draws' => '',
            'pref_1' => '',
            'pref_2' => '',
            'pref_3' => '',
            'pref_4' => '',
            'telephone' => '',
            'mobile' => '',
            'email' => '',
            'gdpr' => '',
            'terms' => '',
            'age' => '',
            'signed' => ''
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
        $from               = new DateTime ($from);
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
        $v = $this->form_vars ();
        $titles = explode (',',BLOTTO_TITLES_WEB);
        require __DIR__.'/form.php';
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
         verify_general ();
        if ($_POST['telephone'] && !verify_phone($_POST['telephone'])) {
            $error = 'Telephone number (landline) is not valid';
        }
        elseif ($_POST['mobile'] && !verify_phone($_POST['mobile'],'m')) {
            $error = 'Telephone number (mobile) is not valid';
        }
        elseif ($_POST['email'] && !verify_email($_POST['email'])) {
            $error = 'Email address is not valid';
        }
        else {
            // Insert into stripe_payment leaving especially `Paid` and `Created` as null
            // $this->txn_ref = something unique to go in button
        }
        if ($error) {
            throw new \Exception ($error);
            return false;
        }
    }

    private function sms_message ( ) {
        $mysqli = new mysqli (STRIPE_DB_HOST,STRIPE_DB_USERNAME,STRIPE_DB_PASSWORD,STRIPE_DB_DATABASE);
        if ($mysqli->connect_errno) {
            throw new \Exception ($mysqli->connecterror);
            return false;
        }
        $project_id = STRIPE_PROJECT_ID;
        $q = "
          SELECT
            `sms_from`
           ,`sms_name`
           ,`sms_phone`
          FROM `projects`
          WHERE `id`=$project_id
        ";
        $ps = $mysqli->query ($q);
        if (!$ps) {
            throw new \Exception ($mysqli->error);
            return false;
        }
        if (!($p=$ps->fetch_assoc())) {
            throw new \Exception ('Project ID '.$project_id.' not found');
            return false;
        }
        $msg = STRIPE_SMS_MESSAGE;
        $msg = str_replace ('{{NAME}}',$p['sms_name'],$msg);
        $msg = str_replace ('{{PHONE}}',$p['sms_phone'],$msg);
        return array ('from'=>$p['sms_from'],'message'=>$msg);
    }

    private function verify_email ($email) {
        $params = array(
            "username" => STRIPE_D8_USERNAME,
            "password" => STRIPE_D8_PASSWORD,
            "email" => $email,
            "level" => STRIPE_EMAIL_VERIFY_LEVEL,
        );
        $client = new SoapClient("https://webservices.data-8.co.uk/EmailValidation.asmx?WSDL");
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
        $dt             = new DateTime ($_POST['dob']);
        if (!$dt) {
            define ( 'STRIPE_GO', 'about');
            throw new \Exception ('Date of birth is not valid');
            return false;
        }
        $now        = new DateTime ();
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
        if (!$_POST['sort_code']) {
            define ( 'STRIPE_GO', 'bank');
            throw new \Exception ('Bank sort code is required');
            return false;
        }
        if (!$_POST['account_number']) {
            define ( 'STRIPE_GO', 'bank');
            throw new \Exception ('Bank account number is required');
            return false;
        }
        if (!array_key_exists('gdpr',$_POST) || !$_POST['gdpr']) {
            define ( 'STRIPE_GO', 'gdpr');
            throw new \Exception ('You must confirm that you have read the GDPR statement');
            return false;
        }
        if (!array_key_exists('signed',$_POST) || !$_POST['signed']) {
            define ( 'STRIPE_GO', 'sign');
            throw new \Exception ('You must confirm your direct debit instruction');
            return false;
        }
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
            "username" => D8_USERNAME,
            "password" => D8_PASSWORD,
            "telephoneNumber" => $number,
            "defaultCountry" => 'GB',
        );
        $params['options']['Option'][] =  array("Name" => "UseMobileValidation", "Value" => false);
        $params['options']['Option'][] =  array("Name" => "UseLineValidation", "Value" => false);
        $client = new SoapClient("https://webservices.data-8.co.uk/InternationalTelephoneValidation.asmx?WSDL");
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

