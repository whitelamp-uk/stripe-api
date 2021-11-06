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
  AND (`failure_code` IS NULL OR `failure_code`='')
  AND (`collection_date` IS NULL OR `collection_date`<=CURDATE())
ORDER BY `id`
;
