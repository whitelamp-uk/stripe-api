<?php

// Organisation
define ( 'BLOTTO_PAY_API_STRIPE',           '/path/to/stripe-api/PayApi.php' );
define ( 'BLOTTO_PAY_API_STRIPE_CLASS',     '\Blotto\Stripe\PayApi'         );
define ( 'BLOTTO_PAY_API_STRIPE_BUY',       true        ); // Provide integration
define ( 'STRIPE_CODE',             'STRP'      ); // CCC and Provider
define ( 'STRIPE_INIT_FILE',        '/path/to/stripe-php-7.77.0/init.php'   );
define ( 'STRIPE_ADMIN_EMAIL',      'stripe.support@my.biz'                 );
define ( 'STRIPE_ADMIN_PHONE',      '01 234 567 890'                        );
define ( 'STRIPE_TERMS' ,           'https://my.biz/terms'                  );
define ( 'STRIPE_PRIVACY' ,         'https://my.biz/privacy'                );
define ( 'STRIPE_EMAIL',            'paypal.account@my.domain'              );
define ( 'STRIPE_CMPLN_EML',        true        ); // Send completion message by email
define ( 'STRIPE_CMPLN_MOB',        false       ); // Send completion message by SMS
define ( 'STRIPE_ERROR_LOG',        false                                   );
define ( 'STRIPE_REFNO_OFFSET',     100000000           );

define ( 'STRIPE_MAX_TICKETS',      10          );
define ( 'STRIPE_MAX_PAYMENT',      50          );

define ( 'STRIPE_D8_USERNAME',          ''      );
define ( 'STRIPE_D8_PASSWORD',          ''      );

define ( 'STRIPE_SECRET_KEY',      '' );
define ( 'STRIPE_PUBLIC_KEY', '' );
define ( 'STRIPE_DEV_MODE', true );


define ( 'CAMPAIGN_MONITOR',        '/path/to/createsend-php/csrest_transactional_smartemail.php' );
define ( 'CAMPAIGN_MONITOR_KEY',    '' );
define ( 'CAMPAIGN_MONITOR_SMART_EMAIL_ID', ''  );

define ( 'VOODOOSMS',               '/home/blotto/voodoosms/SMS.php'        );

define ( 'BLOTTO_SIGNUP_VFY_EML',   true        ); // User must confirm email address
define ( 'BLOTTO_SIGNUP_VFY_MOB',   false       ); // User must confirm phone number



// Global

define ( 'STRIPE_PROVIDER',             'PXXX'          ); // Provider code for mandates
define ( 'STRIPE_CCC',                  'CXXX'          ); // CCC to use in lottery data
define ( 'STRIPE_TABLE_MANDATE',        'blotto_build_mandate'      );
define ( 'STRIPE_TABLE_COLLECTION',     'blotto_build_collection'   );

define ( 'STRIPE_D8_EML_VERIFY_LEVEL',  'MX'            );

