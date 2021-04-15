<?php

// Organisation
define ( 'BLOTTO_PAY_API_CLASS_STRIPE', '/path/to/stripe-api/PayApi.php'    );
define ( 'STRIPE_ADMIN_EMAIL',          'stripe.support@my.biz'             );
define ( 'STRIPE_ADMIN_PHONE',          '01 234 567 890'                    );
define ( 'STRIPE_TERMS' ,                   'https://my.biz/terms'          );
define ( 'STRIPE_PRIVACY' ,                 'https://my.biz/privacy'        );
define ( 'STRIPE_EMAIL',                'stripe.account@my.domain'          );
define ( 'STRIPE_ERROR_LOG',            false                               );
define ( 'STRIPE_CNFM_EM',              true                ); // User must confirm email address
define ( 'STRIPE_CNFM_PH',              false               ); // User must confirm phone number
define ( 'STRIPE_CMPLN_EM',             true                ); // Send completion message by email
define ( 'STRIPE_CMPLN_PH',             false               ); // Send completion message by SMS
define ( 'STRIPE_VOODOOSMS',            '/home/blotto/voodoosms/SMS.php'    );
define ( 'STRIPE_CAMPAIGN_MONITOR', '/path/to/createsend-php/csrest_transactional_smartemail.php' );

define ( 'CAMPAIGN_MONITOR_KEY',    '' );
define ( 'CAMPAIGN_MONITOR_SMART_EMAIL_ID', '' );



// Global

define ( 'STRIPE_PROVIDER',             'PXXX'          ); // Provider code for mandates
define ( 'STRIPE_CCC',                  'CXXX'          ); // CCC to use in lottery data
define ( 'STRIPE_TABLE_MANDATE',        'blotto_build_mandate'      );
define ( 'STRIPE_TABLE_COLLECTION',     'blotto_build_collection'   );

define ( 'STRIPE_D8_USERNAME',          'development@burdenandburden.co.uk' );
define ( 'STRIPE_D8_PASSWORD',          ''              );
define ( 'STRIPE_D8_EML_VERIFY_LEVEL',  'MX'            );

