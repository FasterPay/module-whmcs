CREATE TABLE IF NOT EXISTS `fp_refund_transactions` (
	`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
	`transaction_id` INT(11) UNSIGNED NOT NULL,
	`refund_reference_id` INT(11) UNSIGNED NOT NULL,
	PRIMARY KEY (`id`),
	INDEX `Index 1` (`refund_reference_id`)
)
ENGINE=InnoDB
;
