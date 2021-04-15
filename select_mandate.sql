-- Must be a single select query
SELECT
  '{{STRIPE_PROVIDER}}'
 ,null
 ,`TransactionRef`
 ,`ClientRef`
 ,`Created`
 ,`Updated`
 ,`FirstDrawClose`
 ,'LIVE'
 ,'Monthly'
 ,`Amount`
 ,`Chances`
 ,`Name`
 ,'{{STRIPE_PROVIDER}}'
 ,''
 ,''
 ,`id`
 ,1
 ,`Created`
 ,`Created`
FROM `pponce_payment`
WHERE `Created` IS NOT NULL
  AND `Created`>='{{STRIPE_FROM}}'
ORDER BY `id`
;

