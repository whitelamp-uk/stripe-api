-- Must be a single select query
SELECT
  `Created`
 ,'{{STRIPE_PROVIDER}}'
 ,null
 ,`TransactionRef`
 ,`ClientRef`
 ,`Amount`
FROM `stripe_payment`
WHERE `Created` IS NOT NULL
  AND `Created`>='{{STRIPE_FROM}}'
ORDER BY `id`
;
