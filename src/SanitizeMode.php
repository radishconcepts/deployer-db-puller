<?php

namespace Radishconcepts\Deployer\Wp;

/**
 * How personal data is removed from a pulled database.
 */
enum SanitizeMode: string
{
	/** Empty the data: TRUNCATE tables / delete posts. */
	case Delete = 'delete';

	/** Keep the rows but overwrite personal fields with deterministic fake values. */
	case Anonymize = 'anonymize';
}
