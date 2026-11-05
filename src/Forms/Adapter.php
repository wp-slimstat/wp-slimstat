<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms;

/** A builder translates public lifecycle hooks; shared code owns all tracking. */
interface Adapter {
	/** Register public render and outcome hooks without querying the database. */
	public function register(): void;
}
