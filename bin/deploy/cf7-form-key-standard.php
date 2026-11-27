<?php

/**
 * Which Contact Form 7 forms get which lookup key. Pure: no WordPress calls, so the script (cf7-form-key.php) and the tests share it.
 *
 * A key is stored on the form post as meta `_pkde_form_key` and is found by the kit's App\form_id('key'); the form's title stays free to rename.
 *
 * @param  list<array{id:int,title:string,key:string}>  $forms  every form on the site
 * @param  array<string,string>  $wanted  key => the title the form has today
 * @return list<array{key:string,title:string,action:string,id:int}>  action: already (the key is on a form), tag (set it on this form), missing (no form has
 *                                                                   that title), ambiguous (several do), taken (the form already carries another key), invalid (a bad key)
 */
function pkde_cf7_key_plan(array $forms, array $wanted): array
{
    $plan = [];

    foreach ($wanted as $key => $title) {
        $key = (string) $key;
        $row = ['key' => $key, 'title' => (string) $title, 'action' => '', 'id' => 0];

        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $key) !== 1) {
            $row['action'] = 'invalid';
            $plan[] = $row;

            continue;
        }

        $holder = array_values(array_filter($forms, fn($f) => $f['key'] === $key));
        if ($holder !== []) {
            // A rename never matters: the key is the identity, whatever the title says now.
            $row['action'] = 'already';
            $row['id'] = (int) $holder[0]['id'];
            $plan[] = $row;

            continue;
        }

        $same = array_values(array_filter($forms, fn($f) => strcasecmp(trim($f['title']), trim((string) $title)) === 0));
        if (count($same) > 1) {
            $row['action'] = 'ambiguous';
        } elseif ($same === []) {
            $row['action'] = 'missing';
        } elseif ($same[0]['key'] !== '') {
            $row['action'] = 'taken';
            $row['id'] = (int) $same[0]['id'];
        } else {
            $row['action'] = 'tag';
            $row['id'] = (int) $same[0]['id'];
            // Two keys for one title: the second must not claim the same form.
            foreach ($forms as &$f) {
                if ($f['id'] === $row['id']) {
                    $f['key'] = $key;
                }
            }
            unset($f);
        }

        $plan[] = $row;
    }

    return $plan;
}

/** `key=Title` arguments (what wp eval-file passes) into key => title. No arguments: the template's own form. */
function pkde_cf7_key_wanted(array $args): array
{
    if ($args === []) {
        return ['contact' => 'Contact form'];
    }

    $wanted = [];
    foreach ($args as $arg) {
        $pos = strpos((string) $arg, '=');
        if ($pos !== false) {
            $wanted[trim(substr((string) $arg, 0, $pos))] = trim(substr((string) $arg, $pos + 1));
        }
    }

    return $wanted;
}

/** The keys a theme's PHP/Blade files ask for with form_id('x') / form_shortcode('x') (no argument means 'contact'), and the files that still find a form by title. */
function pkde_cf7_key_scan(string $source): array
{
    $keys = [];
    if (preg_match_all('/\bform_(?:id|shortcode)\(\s*([\'"])([a-z0-9-]+)\1/', $source, $m)) {
        $keys = $m[2];
    }
    if (preg_match('/\bform_(?:id|shortcode)\(\s*\)/', $source) === 1) {
        $keys[] = 'contact';
    }

    return ['keys' => array_values(array_unique($keys)), 'by_title' => preg_match('/get_page_by_title\([^;]*wpcf7_contact_form/s', $source) === 1];
}
