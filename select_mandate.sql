-- Must be a single select query
SELECT
  '{{STRIPE_CODE}}'
 ,null
 ,`refno`
 ,`cref`
 ,`created`
 ,`paid`
 ,DATE_ADD(DATE(`paid`),INTERVAL 1 DAY)
 ,'LIVE'
 ,'Single'
 ,`amount`
 ,`quantity`
 ,CONCAT_WS(' ',`title`,`name_first`,`name_last`)
 ,''
 ,''
 ,''
 ,`id`
 ,1
 ,`created`
 ,`created`
FROM `stripe_payment`
WHERE `created` IS NOT NULL
  AND `created`>='{{STRIPE_FROM}}'
  AND `paid` IS NOT NULL
ORDER BY `id`
;

