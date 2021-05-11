-- Must be a single select query
SELECT
  `created`
 ,'{{STRIPE_CODE}}'
 ,null
 ,`refno`
 ,`cref`
 ,`amount`
FROM `stripe_payment`
WHERE `created` IS NOT NULL
  AND `created`>='{{STRIPE_FROM}}'
  AND `paid` IS NOT NULL
ORDER BY `id`
;
