-- Must be a single select query
SELECT
  '{{STRIPE_CODE}}'
 ,null
 ,`refno`
 ,`cref`
 ,`created`
 ,DATE(`callback_at`)
 ,DATE_ADD(DATE(`callback_at`),INTERVAL 1 DAY)
 ,IF(LENGTH(`failure_code`)>0,'FAILED','LIVE')
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
WHERE `created`>='{{STRIPE_FROM}}'
  AND `callback_at` IS NOT NULL
ORDER BY `id`
;

