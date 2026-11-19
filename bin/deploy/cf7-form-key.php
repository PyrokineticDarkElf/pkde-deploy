<?php

/**
 * Tags Contact Form 7 forms with a stable lookup key (TPL-186, WP-83), so a theme finds a form with App\form_id('key') and a rename of the form never breaks it.
 * The key is stored on the form post as meta `_pkde_form_key`. Several forms in one run; each form is tagged once; running it again changes nothing. It prints the key
 * and the form ID, nothing else, and writes one post meta per new tag.
 *
 *   bash bin/deploy/wp.sh eval-file bin/deploy/cf7-form-key.php
 *   bash bin/deploy/wp.sh eval-file bin/deploy/cf7-form-key.php contact="Contact Form" park-home-enquiry="Park Home Enquiry"
 *
 * With no arguments it tags the form called "Contact form" (the template's own) with the key `contact`. The arguments are key="the form's title today" pairs: titles
 * are not secret. A key is lower case letters, digits and dashes. `contact` is the standard key for the contact page's form; a site may add its own. A title that
 * matches no form, or several, or a form that already carries another key, is reported and not touched (the run then exits 1).
 *
 * Locally: docker compose run --rm wpcli wp --allow-root eval-file vendor/pkde/deploy/bin/deploy/cf7-form-key.php
 */
require_once __DIR__ . '/cf7-form-key-standard.php';

if (! post_type_exists('wpcf7_contact_form')) {
    echo "Contact Form 7 is not active: nothing to tag.\n";

    return;
}

$forms = [];
foreach (get_posts(['post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC']) as $p) {
    $forms[] = ['id' => (int) $p->ID, 'title' => (string) $p->post_title, 'key' => (string) get_post_meta($p->ID, '_pkde_form_key', true)];
}

$failed = false;
foreach (pkde_cf7_key_plan($forms, pkde_cf7_key_wanted($args ?? [])) as $row) {
    if ($row['action'] === 'tag') {
        update_post_meta($row['id'], '_pkde_form_key', $row['key']);
        echo "{$row['key']} -> form #{$row['id']} (tagged)\n";
    } elseif ($row['action'] === 'already') {
        echo "{$row['key']} -> form #{$row['id']} (already tagged)\n";
    } else {
        $failed = true;
        $why = ['missing' => 'no form has that title', 'ambiguous' => 'several forms have that title: rename one in wp-admin first', 'taken' => 'that form already carries another key', 'invalid' => 'not a valid key (lower case letters, digits, dashes)'][$row['action']] ?? $row['action'];
        echo "{$row['key']} -> NOT TAGGED: $why\n";
    }
}

if ($failed) {
    exit(1);
}
