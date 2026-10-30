<?php
/*
 * Copyright (c) 2017-2026 Ysard
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://gnu.org>.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Pelican blog search page, using SphinxQL over the MySQL wire protocol.
 * Requires PHP pdo_mysql and intl extensions, plus a SphinxQL listener on
 * 127.0.0.1:9306. The index name is by default set to my_blog, but can be
 * modified thanks to the `SPHINX_INDEX` variable declared in Pelican configuration.
 */

$found = null;
$raw_q = '';
$searchError = false;
$invalidQuery = false;

// Escape values at the point where they are inserted into HTML, not before
// searching. Non-scalar values are rejected to avoid array-to-string warnings.
$escapeHtml = static function ($value): string {
    if (!is_scalar($value) && $value !== null) {
        $value = '';
    }
    // Do not encode html entities twice (double_encode)
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
};

if (array_key_exists('q', $_GET)) {
    // Reject arrays and other unexpected query-string shapes (e.g. q[]=x).
    if (!is_string($_GET['q'])) {
        http_response_code(400);
        $invalidQuery = true;
    } else {
        $raw_q = trim($_GET['q']);

        if ($raw_q !== '') {
            // Bound query size and reject malformed UTF-8/control characters.
            // The byte limit also caps the amount of work sent to searchd.
            // Purge bots that follow template string of search action schema.
            $containsForbiddenControl = preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw_q) === 1;
            if (
                $raw_q === '{search_term_string}' ||
                strlen($raw_q) > 128 ||
                preg_match('//u', $raw_q) !== 1 ||
                $containsForbiddenControl
            ) {
                http_response_code(400);
                $invalidQuery = true;
                $raw_q = '';
            } else {
                // Preserve the original search page's boolean-operator mapping.
                // This changes Sphinx's MATCH expression, not the surrounding SQL.
                $query = str_replace(
                    array(' OR ', ' or ', ' AND ', ' and ', 'NOT ', 'not '),
                    array(' | ',  ' | ',  ' & ',  ' & ',  '! ',   '! '),
                    $raw_q
                );

                $pdo = null;
                try {
                    // ATTR_EMULATE_PREPARES:
                    // Sphinx 2.x exposes SphinxQL over the MySQL wire protocol,
                    // but does not document/support native server-side prepared
                    // statements. PDO therefore uses emulated prepared statements
                    // here: the query stays parameterized in PHP, and PDO quotes
                    // the bound value before sending the resulting SQL to searchd.
                    // During a migration to Manticore, should be set to false.
                    $dsn = 'mysql:host=127.0.0.1;port=9306;charset=utf8';
                    $pdo = new PDO($dsn, '', '', array(
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_TIMEOUT => 2,
                        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                    ));

                    // Keep the SQL structure constant. Only the MATCH() value is
                    // supplied by the visitor, through a PDO parameter binding.
                    $sql = "SELECT *, WEIGHT() AS search_weight " .
                           "FROM {{ SPHINX_INDEX | default('my_blog', true) }} WHERE MATCH(?) " .
                           "ORDER BY WEIGHT() DESC LIMIT 0, 20";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(1, $query, PDO::PARAM_STR);
                    $stmt->execute();

                    $matches = array();
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $weight = (int) ($row['search_weight'] ?? 0);
                        unset($row['id'], $row['search_weight']);

                        // Adapt each SphinxQL row to the old SphinxClient-style
                        // structure used by the existing rendering code.
                        $matches[] = array(
                            'weight' => $weight,
                            'attrs' => $row,
                        );
                    }
                    $stmt->closeCursor();

                    // SHOW META applies to the immediately preceding SELECT and
                    // must run on the same connection.
                    $metaStmt = $pdo->query("SHOW META");
                    $meta = $metaStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    $metaStmt->closeCursor();

                    // Do not use 'total_found', since it contains the total number of
                    // matches in the dataset before the LIMIT statement is applied.
                    $found = array(
                        'status' => 0,
                        'matches' => $matches,
                        'total_found' => count($matches),
                        /*
                        'total_found' => isset($meta['total_found'])
                            ? (int) $meta['total_found']
                            : count($matches),
                        */
                        'time' => isset($meta['time']) ? (float) $meta['time'] : 0.0,
                    );
                } catch (Throwable $exception) {
                    $searchError = true;
                    error_log(
                        '[pelican-search] SphinxQL/PDO request failed'
                        . '; <' . (string) $exception->getMessage()
                        . '>; in ' . (string) $exception->getFile() . ':' . (int) $exception->getLine()
                    );
                } finally {
                    $pdo = null;
                }
            }
        }
    }
}
?>
{% extends "base.html" %}
{% block description %}{{ _("Search results for %(sitename)s blog.", sitename=SITENAME|striptags|e) }}{% endblock %}
{% block title %}{{ _("Search") }} — {{ super() }}{% endblock %}
{% block content %}
<h1><?php echo '{{ _("Results for") }} "' . $escapeHtml($raw_q) . '":'; ?></h1>

<?php
if (isset($found) && $found !== null && $found['status'] === 0) {
    if ($found['total_found'] !== 0) {
        // Internationalized date (requires PHP intl).
        $formatter = new IntlDateFormatter('{{ _("en") }}', IntlDateFormatter::SHORT, IntlDateFormatter::SHORT);
        $formatter->setPattern('E d MMM yyyy');

        foreach ($found['matches'] as $document) {
            $attrs = $document['attrs'];

            $date = new DateTime();
            $date->setTimestamp((int) ($attrs['published'] ?? 0));

            $categoryValue = $attrs['category'] ?? '';
            $category = is_scalar($categoryValue) ? (string) $categoryValue : '';
            $categoryUrl = $attrs['category_url'] ?? '';

            $authorsData = json_decode((string) ($attrs['authors'] ?? ''), true);
            $authors = is_array($authorsData) ? array_keys($authorsData) : array();
            $nbAuthors = count($authors);

            $tagsData = json_decode((string) ($attrs['tags'] ?? ''), true);
            $tags = is_array($tagsData) ? $tagsData : array();
            ?>
            <article class="article" itemscope itemtype="https://schema.org/BlogPosting">
                <a href="{{ SITEURL }}/<?php echo $escapeHtml($attrs['url'] ?? ''); ?>">
                    <h2 itemprop="headline"><?php echo $escapeHtml($attrs['title'] ?? ''); ?></h2>
                </a>
                <time datetime="<?php echo $escapeHtml($date->format('c')); ?>" itemprop="datePublished"><?php echo $escapeHtml($formatter->format($date) ?: ''); ?></time>
                &nbsp;—&nbsp;
                <?php
                // Authors
                foreach ($authors as $index => $author) {
                    ?>
                    <span class="author-name" itemprop="author" itemscope itemtype="https://schema.org/Person">
                        <span itemprop="name"><?php echo $escapeHtml($author); ?></span>
                    </span>
                    <?php
                    if ($index + 1 < $nbAuthors) {
                        echo ' &amp; ';
                    }
                }
                ?>
                <div class="summary" itemprop="abstract"><?php echo $escapeHtml($attrs['summary'] ?? ''); ?></div>
                {{ _("Category:") }}
                <span itemprop="articleSection">
                    <a href="{{ SITEURL }}/<?php echo $escapeHtml($categoryUrl); ?>" rel="category"><?php echo $escapeHtml($category); ?></a>
                </span>
                <?php
                // Tags
                if (!empty($tags)) {
                    echo '{{ _("Tags:") }}';
                    foreach ($tags as $tagName => $tagUrl) {
                        ?>
                        <span itemprop="keywords">
                            <a href="{{ SITEURL }}/<?php echo $escapeHtml($tagUrl); ?>" rel="tag"><?php echo $escapeHtml($tagName); ?></a>
                        </span>&nbsp;
                        <?php
                    }
                }
                ?>
            </article>
            <hr>
            <?php
        }

        echo '<p>' . (int) $found['total_found'] . ' {{ _("article(s) found in") }} ' .
             number_format((float) $found['time'], 3, '.', '') . 's.</p>';
    } else {
        echo '<p>{{ _("There are no results for your query.") }}</p>';
    }
} elseif ($invalidQuery) {
    echo '<p>{{ _("The search query is invalid.") }}</p>';
} elseif ($searchError) {
    // Backend details are intentionally hidden from the public response.
    echo '<p>{{ _("Search is temporarily unavailable.") }}</p>';
} else {
    echo '<p>{{ _("Did you forget the query?") }}</p>';
}
?>
{% endblock content %}
