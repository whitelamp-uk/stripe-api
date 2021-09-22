-- Must be a single select query
SELECT
  `created`
 ,'{{STRIPE_CODE}}'
 ,null
 ,`refno`
 ,`cref`
 ,`amount`
FROM `stripe_payment`
WHERE `created`>='{{STRIPE_FROM}}'
  AND `callback_at` IS NOT NULL
  AND LENGTH(`failure_code`)=0
ORDER BY `id`
;
