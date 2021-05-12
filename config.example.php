<?php

// Organisation - Stripe
define ( 'BLOTTO_PAY_API_STRIPE',           '/path/to/stripe-api/PayApi.php' );
define ( 'BLOTTO_PAY_API_STRIPE_CLASS',     '\Blotto\Stripe\PayApi'         );
define ( 'BLOTTO_PAY_API_STRIPE_BUY',       true        ); // Provide integration
define ( 'STRIPE_INIT_FILE',        '/path/to/stripe-php-7.77.0/init.php'   );
define ( 'STRIPE_CODE',             'STRP'      ); // CCC and Provider
define ( 'STRIPE_CMPLN_MOB',        false       ); // Send completion message by SMS
define ( 'STRIPE_CMPLN_EML',        true        ); // Send completion message by email
define ( 'STRIPE_CMPLN_EML_CM_ID',  ''          );
define ( 'STRIPE_ERROR_LOG',        false       );
define ( 'STRIPE_REFNO_OFFSET',     100000000   );
define ( 'STRIPE_DESCRIPTION',      'My Org Lottery'    );
define ( 'STRIPE_SECRET_KEY',       ''          );
define ( 'STRIPE_PUBLIC_KEY',       ''          );
define ( 'STRIPE_DEV_MODE',         true        );


// Organisation - all payment providers
define ( 'BLOTTO_DEV_MODE',         true        );
define ( 'BLOTTO_ADMIN_EMAIL',      'help@my.biz'                           );
define ( 'BLOTTO_ADMIN_PHONE',      '01 234 567 890'                        );
define ( 'BLOTTO_MAX_PAYMENT',      50          );
define ( 'BLOTTO_SIGNUP_CM_ID',     ''          ); // Verify email
define ( 'BLOTTO_SIGNUP_PRIVACY',   'https://my.biz/privacy'                );
define ( 'BLOTTO_SIGNUP_SMS_FROM',  ''          ); // Verify SMS
define ( 'BLOTTO_SIGNUP_TERMS',     'https://my.biz/terms'                  );
define ( 'BLOTTO_SIGNUP_VFY_EML',   true        ); // User must confirm email address
define ( 'BLOTTO_SIGNUP_VFY_MOB',   false       ); // User must confirm phone number
define ( 'CAMPAIGN_MONITOR',        '/path/to/createsend-php/csrest_transactional_smartemail.php' );
define ( 'CAMPAIGN_MONITOR_KEY',    ''          );
define ( 'CAMPAIGN_MONITOR_SMART_EMAIL_ID', ''  );
define ( 'DATA8_USERNAME',          ''          );
define ( 'DATA8_PASSWORD',          ''          );
define ( 'DATA8_COUNTRY',           'GB'        );
define ( 'VOODOOSMS',               '/home/blotto/voodoosms/SMS.php' );


// Global - Stripe
define ( 'STRIPE_TABLE_MANDATE',    'blotto_build_mandate'      );
define ( 'STRIPE_TABLE_COLLECTION', 'blotto_build_collection'   );
define ( 'STRIPE_CALLBACK_IPS_URL', 'https://stripe.com/files/ips/ips_webhooks.json' );
define ( 'STRIPE_CALLBACK_IPS_TO',  30          ); // seconds before giving up getting safe IPs


// Global - all payment providers
define ( 'DATA8_EMAIL_LEVEL',       'MX'        );
define ( 'VOODOOSMS_DEFAULT_COUNTRY_CODE', 44   );
define ( 'VOODOOSMS_FAIL_STRING',   'Sending SMS failed'        );
define ( 'VOODOOSMS_JSON',          __DIR__.'/voodoosms.cfg.json' );

