<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'etc_cache';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.2.7';
$plugin['author'] = 'Oleg Loukianov';
$plugin['author_uri'] = 'www.iut-fbleau.fr/projet/etc/';
$plugin['description'] = 'Events-driven cache';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '5';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '2';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@etc_cache
etc_cache_actions => Actions
etc_cache_cached_at => Cached at
etc_cache_filter => Filter
etc_cache_heading => Cached items
etc_cache_no_cached_items => No cached items recorded.
etc_cache_reset => Reset
etc_cache_tab => Cache
EOT;

// End of textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
Txp::get('\Textpattern\Tag\Registry')->register('etc_cache');

if (@txpinterface == 'admin') {
    register_callback('etc_cache_install', 'plugin_lifecycle.etc_cache');
    new etc_Cache();
} elseif (serverSet('HTTP_USER_AGENT') == 'etc_cache') {
    global $nolog;
    $nolog = true;
}

// -------------------------------------------------------------

function etc_cache($atts, $thing = null)
{
    global $prefs, $pretext;
    static $lastmod = null, $times = array();

    extract(lAtts(array(
        'id'    => !empty($atts['form']) ? $atts['form'] : null,
        'form'  => '',
        'reset' => null,
        'time'  => true
    ), $atts));

    if (empty($id)) {
        trigger_error(gTxt('form_not_specified'));

        return;
    }

    $id = doSlash($id);
    $now = time();

    if (!isset($lastmod)) {
        $lastmod = strtotime($prefs['lastmod']);
    }

    if (empty($time)) {
        $update = 0;
    } elseif ($time === true) {
        $update = $lastmod;
    } elseif (!isset($times[$time = (string)$time])) {
        $update = is_numeric($time) ? $now + (float)$time : (int)strtotime($time, $now);

        if ($update > $now) {
            if (!is_numeric($time)) {
                $date = date_parse($time);

                foreach (array('year', 'month', 'day', 'hour', 'minute', 'second', 'fraction') as $key) {
                    if ($date[$key] !== false) {
                        $update = -1;
                        break;
                    }
                }
            }
        }

        $times[$time] = $update;
    } else {
        $update = $times[$time];
    }

    $cached = $update >= 0 ? safe_row('reset, UNIX_TIMESTAMP(time) AS time, url, text', 'etc_cache', "id = '$id' AND time IS NOT NULL") : false;
    $onlastmod = $cached && ($time === true || $update > $now) && ($cached['reset'] == '%' || $cached['reset'] == '1');

    if ($update > $now) {
        $update = $onlastmod ? max(2*$now - $update, $lastmod) : 2*$now - $update;
//    } elseif ($time !== true) {
//        if (!isset($reset) && !isset($cached['reset'])) $reset = '';
    } elseif ($cached && !$onlastmod && $time === true) {
        $update = 0;
    }

    $parse = !$cached || $update < 0 || $cached['time'] < $update;

    if ($parse) {
        $out = $form ? parse_form($form, $thing) : parse($thing);

        if ($update >= 0) {
            $renew = ($reset || !isset($cached['url']) ? "url='".doSlash($pretext['req'])."'," : '')
                .(isset($reset) && $reset !== true ? "reset='".doSlash($reset)."'," : '');

            safe_upsert('etc_cache', "time = NOW(), $renew text = '".doSlash($out)."'", "id = '$id'");
        }
    } else {
        $out = $cached['text'];
    }

    return $out;
}

/********** Admin class ***********/

class etc_Cache
{
public function __construct()
{
	add_privs('etc_cache', '1,2');
	register_tab('extensions', 'etc_cache', gTxt('etc_cache_tab'));
	register_callback(array($this, 'update'), 'site.update');
	register_callback(array($this, 'tab'), 'etc_cache');
}

public function update($event, $step = '', $rs = null)
{
    $urls = $ids = array();
    $safe_step = $step ? doSlash($step) : '';
    $filter = "'$safe_step' LIKE reset".($step ? " OR FIND_IN_SET('$safe_step', reset)" : '');

    foreach (safe_rows('id, url, filter', 'etc_cache', "url > '' AND reset > '' AND ($filter)") as $cache) {
        if ($step && !empty($cache['filter'])) {
            $items = json_decode($cache['filter'], true);

            if (isset($items[$step]) && is_array($items[$step])) {
                foreach ($items[$step] as $field => $value) {
                    if (isset($rs[$field]) && !in_array($rs[$field], (array)$value)) {
                        continue 2;
                    }
                }
            }
        }

        $ids[] = $cache['id'];

        if (!in_array($cache['url'], $urls)) {
            $urls[] = $cache['url'];
        }
    }

    if ($ids) {
        safe_update('etc_cache', 'time = NULL', "id IN (".implode(',', quote_list($ids)).")");
        $this->ping($urls);
        safe_delete('etc_cache', "time IS NULL AND url > '' AND reset > '' AND ($filter)");
        safe_optimize('etc_cache');
    }
}

private function ping ($urls = array()) {
	static $context = null;
	if (empty($urls)) return;
	if (!isset($context)) {
		$context = stream_context_create(array('http'=>array(
			'method'=>'GET',
			'header'=>"Cache-Control: max-age=0, no-store, no-cache\r\n" .
				"Pragma: no-cache\r\n" .
				"User-Agent: etc-cache\r\n"
			)
		));
	}

	foreach ((array)$urls as $url) {
		$url = preg_match('/^\s*https?:/', $url) ? $url : hu.ltrim($url, '/ ');
		file_get_contents($url, false, $context);
	}
}

/*************** admin *****************/

public function tab($event, $step) {
	global $prefs;

	$qid = quote_list($id = gps('id'));

	if($step == 'save' && bouncer($step, array('save'=>true))) switch(gps('save')) {
		case gTxt('save') : if ($id) safe_upsert('etc_cache',
			"reset='".doSlash(gps('reset'))."',
			url='".doSlash(gps('url'))."',
			filter='".doSlash(gps('filter'))."'",
			"id=$qid");
		break;
		case gTxt('delete') : safe_delete('etc_cache', $id ? "id=$qid" : '1');
		break;
		case gTxt('update') : safe_update('etc_cache', 'time=NULL', $id ? "id=$qid" : '1');
		if ($id && $url = safe_field('url', 'etc_cache', "id=$qid")) {
			$this->ping($url);
		}
		break;
	}

	$rs = safe_rows('id, time, url, reset, filter, IF(LENGTH(text)>512, CONCAT(LEFT(text, 504), " ..."), text) AS text', 'etc_cache', '1 ORDER BY time DESC');
	$now = date_create('now');

	pagetop("etc_cache");

    echo n.'<div class="txp-layout">'.
        n.tag(
            hed(gTxt('etc_cache_heading'), 1, array('class' => 'txp-heading')),
            'div', array('class' => 'txp-layout-1col')
        );

	echo n.tag_start('div', array(
            'class' => 'txp-layout-1col',
            'id'    => $event.'_container',
        ));

    if ($rs) {
        echo n.tag_start('div', array('class' => 'txp-listtables')).
            n.tag_start('table', array('class' => 'txp-list--no-options')).
            n.tag_start('thead').
            tr(
                n.'<th>'.dLink('etc_cache', 'save', 'save', 'Delete').n.'</th>'.
                n.'<th>ID</th>'.
                n.'<th>'.gTxt('etc_cache_cached_at').'</th>'.
                n.'<th>URL</th>'.
                n.'<th>'.gTxt('etc_cache_reset').'</th>'.
                n.'<th>'.gTxt('etc_cache_filter').'</th>'.
                n.'<th>'.gTxt('etc_cache_actions').'</th>'
            ).
            n.tag_end('thead').
            n.tag_start('tbody');

    	foreach($rs as $row) {
    		extract($row);
    		$class = 'date'.($reset == '%' && $time < $prefs['lastmod'] ? ' warning' : '');
    		$datetime = date_create($time);
    		$diff = $datetime->diff($now);
    		$days = $diff->format('%a');
    		$diff = (!$days ? '' : "$days day".($days == 1 ? '' : 's'). ' ').$diff->format('%H:%I hours old');

    		echo n.'<form method="post" action="?event=etc_cache">'.
                n.'<tr>'.
    		    n.'<td>'.
                n.tag(
                    span(gTxt('delete'), array('class' => 'ui-icon ui-icon-close')),
                    'button',
                    array(
                        'name'      => 'save',
                        'value'      => 'Delete',
                        'class'      => 'destroy',
                        'type'       => 'submit',
                        'title'      => gTxt('delete'),
                        'aria-label' => gTxt('delete'),
                    )
                ).'</td>'.
                n.'<td title="'.doSpecial($text).'">'.doSpecial($id).'</td>'.
                n.'<td class="'.$class.'">'.doSpecial($time).' <small>('.$diff.')</small></td>'.
                n.'<td>'.fInput('text', 'url', $url, '', '', '', INPUT_MEDIUM).n.'</td>'.
                n.'<td>'.fInput('text', 'reset', $reset, '', '', '', INPUT_MEDIUM).n.'</td>'.
                n.'<td>'.fInput('text', 'filter', $filter, '', '', '', INPUT_MEDIUM).n.'</td>'.
    		    n.'<td>'.fInput('submit', 'save', gTxt('update')).fInput('submit', 'save', gTxt('save')).n.'</td>'.
    		    sInput('save').
                hInput('id', $id).
                tInput().
    		    n.'</tr>'.n.'</form>';
    	}

        echo n.tag_end('tbody').
            n.tag_end('table').
            n.tag_end('div');
    } else {
        echo graf(
            span(null, array('class' => 'ui-icon ui-icon-info')).' '.
            gTxt('etc_cache_no_cached_items'),
            array('class' => 'alert-block information')
        );
    }

    echo n.tag_end('div'). // End of .txp-layout-1col.
        n.'</div>'; // End of .txp-layout.
}
//------- end class
}

/*************** install *****************/

function etc_cache_install($event='', $step='')
{
	if($step == 'deleted') {
		safe_delete('txp_prefs', "name LIKE 'etc\_cache\_%'");
		safe_query('DROP TABLE IF EXISTS '.safe_pfx('etc_cache'));
		return;
	}
	if($step == 'enabled') {
		$qc="CREATE TABLE IF NOT EXISTS ".safe_pfx('etc_cache')." (";
		$qc.= <<<EOF
			`id` varchar(64) NOT NULL,
			`url` varchar(255) DEFAULT NULL,
			`time` DATETIME DEFAULT NULL,
			`reset` varchar(255) DEFAULT '1',
			`filter` varchar(767) DEFAULT NULL,
			`text` mediumtext,
			PRIMARY KEY (`id`)
			) ENGINE=MyISAM CHARACTER SET=utf8 ;
EOF;
		safe_query($qc);
		return;
	}
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. etc_cache

"Download":https://github.com/etc-plugins/etc_cache/releases | "Packagist":https://packagist.org/packages/etc-plugins/etc_cache

This Textpattern plugin provides an events-driven cache solution for Textpattern CMS.

Textpattern is fast, but when you have thousands of articles, processing the whole list (say, for creating a sitemap) can become time consuming. It's a good idea (unless you publish an article every minute) to cache the processed result. Naturally, the cached block must be updated when content/an article is added/deleted. Most caching plugins trigger this update when the corresponding page is visited after the site update - this has, however, two inconveniences:

# The first visitor has to wait while the expired block is processed and cached again
# Every site modification, even irrelevant to the cached content, yields the cache update

That’s what etc_cache is aimed to solve.

h2. Installing

Using "Composer":https://getcomposer.org:

bc. $ composer require etc-plugins/etc_cache:*

Or download the latest version of the plugin from "the GitHub project page":https://github.com/etc-plugins/etc_cache/releases, paste the code into the Textpattern Plugins administration panel, install and enable the plugin. Visit the "forum thread":https://forum.textpattern.io/viewtopic.php?id=47702 for more info or to report on the success or otherwise of the plugin.

h2. Requirements

* Textpattern 4.6.0 or newer.

h2. Tags

The basic usage is:

bc. <txp:etc_cache id="heavycode">
    ...heavy...
</txp:etc_cache>

h4. Attributes

* @id="id name"@<br />A unique identifier name for this cached item.
* @reset="value"@<br />See reset information below.
* @time="value"@<br />See time information below.

The code will be processed and cached until the site is updated. On site update, the plugin (if configured so) will ping the URL containing this block, triggering the cache refresh. Hence, the cache will always stay up to date, without penalizing site visitors.

To configure automatic cache updates, visit Extensions region Cache administration panel and edit reset field of each block. The possible values are:

* (empty): update client-side only when expired, regardless the site updates
* @1@: (default) update client-side if expired or the site was updated
* a list of events like @article_posted, article_saved@ or @SQL LIKE@ pattern like @article%@: update server-side when a matching event is fired.

The value @%@ thus means 'auto-update on each site update', but will act as @1@ client-side too.

You can be more specific with cache reset criteria. Say, if you need a block to be reset only if the article 3 is updated, set:

bc. reset: article_saved
filter: {"article_saved":{"ID":3}}

You can also pass a @reset@ attribute directly to etc_cache:

bc. <txp:etc_cache id="archive" reset="article%">
    ...heavy code building an articles archive...
</txp:etc_cache>

If needed, one can pass a @time@ attribute to etc_cache:

bc. <txp:etc_cache id="dailycode" time="+1 day">
    ...daily code...
</txp:etc_cache>

A positive (relative) value of time will indicate that the cache (even a fresh one) must be reset on site update.

An absolute value like @time='<txp:modified format="%F %T" gmt="1" /> +1 month'@ will mean 'cache it if not modified since one month'.

A negative value will not observe site updates, for example (@-900@ seconds is equiavlent to 15 minutes):

<txp:etc_cache id="api-feed" time="-900">
    ...feed code...
</txp:etc_cache>

h2. Authors/credits

Written by "Oleg Loukianov":http://www.iut-fbleau.fr/projet/etc/. Many thanks to "all additional contributors":https://github.com/etc-plugins/etc_cache/graphs/contributors.
# --- END PLUGIN HELP ---
-->
<?php
}
?>
