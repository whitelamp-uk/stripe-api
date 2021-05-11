

-- A persistent table in BLOTTO_MAKE_DB
CREATE TABLE IF NOT EXISTS `stripe_payment` (
  `id` INT (11) NOT NULL AUTO_INCREMENT,
  `paid` datetime DEFAULT NULL,
  `refno` bigint(20) unsigned DEFAULT NULL,
  `cref` varchar(255) CHARACTER SET ascii DEFAULT NULL,
  `quantity` tinyint(3) unsigned NOT NULL,
  `draws` tinyint(3) unsigned NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `name_first` varchar(255) NOT NULL,
  `name_last` varchar(255) NOT NULL,
  `dob` date NOT NULL,
  `email` varchar(255) CHARACTER SET ascii NOT NULL,
  `mobile` varchar(255) CHARACTER SET ascii NOT NULL,
  `telephone` varchar(255) CHARACTER SET ascii NOT NULL,
  `postcode` varchar(255) CHARACTER SET ascii NOT NULL,
  `address_1` varchar(255) NOT NULL,
  `address_2` varchar(255) NOT NULL,
  `address_3` varchar(255) NOT NULL,
  `town` varchar(255) NOT NULL,
  `county` varchar(255) NOT NULL,
  `gdpr` tinyint(1) unsigned NOT NULL,
  `terms` tinyint(1) unsigned NOT NULL,
  `pref_1` varchar(255) NOT NULL,
  `pref_2` varchar(255) NOT NULL,
  `pref_3` varchar(255) NOT NULL,
  `pref_4` varchar(255) NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created` (`created`),
  KEY `paid` (`paid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
;

