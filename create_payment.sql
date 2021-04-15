

-- A persistent table in BLOTTO_MAKE_DB
CREATE TABLE IF NOT EXISTS `stripe_payment` (
  `id` INT (11) NOT NULL AUTO_INCREMENT,
  `txn_ref` varchar(255) CHARACTER SET ascii DEFAULT NULL,
  `client_ref` varchar(255) CHARACTER SET ascii NOT NULL,
  `initialised` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid` datetime DEFAULT NULL,
  `created` date DEFAULT NULL,
  `chances` tinyint(3) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `pref_1` varchar(255) NOT NULL,
  `pref_2` varchar(255) NOT NULL,
  `pref_3` varchar(255) NOT NULL,
  `pref_4` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_ref` (`transaction_ref`),
  UNIQUE KEY `client_ref` (`client_ref`),
  KEY `initialised` (`initialised`),
  KEY `created` (`created`),
  KEY `amount` (`amount`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
;

