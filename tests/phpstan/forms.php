<?php
/** Optional builder signatures for static analysis. Never included by the plugin. */
class WPCF7_ContactForm {
    public static function get_current(): ?self {}
    public function id(): int {}
    public function is_demo_mode(): bool {}
}
class WPForms {}
class GFForms {}
class Forminator {}
class FrmAppHelper {}
class Ninja_Forms {}
class MW_WP_Form {}
class FrmEntry {
    public static function getOne($entry_id, $meta = false) {}
}
class FrmEntriesHelper { const SPAM_ENTRY_STATUS = 4; }
