# stripe-api.git
----------------

A blotto2 API class for interacting with Stripe on (at least) a single payment basis


# Recommended rules for UK gambling legal compliance

Block if ::product_name:: = 'LOTTERY-ONE-OFF-PAYMENT' and :card_funding: = 'credit'
Block if ::product_name:: = 'LOTTERY-ONE-OFF-PAYMENT' and :card_funding: = 'unknown'

Where 'LOTTERY-ONE-OFF-PAYMENT' is an example value for the configurable
constant STRIPE_PRODUCT_NAME


