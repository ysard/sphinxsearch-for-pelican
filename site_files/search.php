/*
This file is part of Shinxsearch for Pelican

Copyright (C) 2017-2022 Ysard

Shinxsearch for Pelican is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as
published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version.

Shinxsearch for Pelican is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with Shinxsearch for Pelican.  If not, see <http://www.gnu.org/licenses/>.
*/
<?php
    //Sanitize the input
    if (isset($_GET['q']) AND !empty($_GET['q'])) {

        $raw_q = htmlspecialchars((string)$_GET['q']);
        $q = $raw_q;

        // Support of booleans operators
        // NOT is preceed by OR or AND or nothing
        // => no space before it (people don't send 2 spaces but only 1 after OR or AND)
        $booleans = array(' OR ', ' or ', ' AND ', ' and ', 'NOT ', 'not ');
        $bool_ops = array(' | ', ' | ', ' && ', ' && ', '! ', '! ');
        $q = str_replace($booleans, $bool_ops, $q);

        $sphinx = new SphinxClient;
        $sphinx->setServer('localhost', 9312);
        $sphinx->setSortMode(SPH_SORT_RELEVANCE);
        // enable extended query syntax where you could combine "AND", "OR", "NOT" operators
        // http://sphinxsearch.com/docs/current.html#extended-syntax
        $sphinx->setMatchMode(SPH_MATCH_EXTENDED2);
        $sphinx->setConnectTimeout(2);

        // Returns FALSE on failure.
        // Query on 'my_blog' index
        $found = $sphinx->query($q, 'my_blog');

        //var_dump($q);
        //echo '<pre>', print_r($found, true), '</pre>';
    }
/*
Array
(
    [error] =>
    [warning] =>
    [status] => 0
    [fields] => Array
        (
            [0] => content
            [1] => title
        )

    [attrs] => Array
        (
            [published] => 2
            [category] => 1073741825
            [title] => 7
            [author] => 7
            [url] => 7
            [summary] => 7
            [slug] => 7
        )

    [matches] => Array
        (
            [637352186] => Array
                (
                    [weight] => 1678
                    [attrs] => Array
                        (
                            [published] => 1482102000
                            [category] => Array
                                (
                                )

                            [title] => mock title
                            [author] => mock user
                            [url] => /mock.html
                            [summary] => mock summary.
                            [slug] => mock_slug
                        )

                )

                [total] => 2
    [total_found] => 2
    [time] => 0
    [words] => Array
        (
            [mock] => Array
                (
                    [docs] => 2
                    [hits] => 2
                )
        )
)
*/
?>
{% extends "base.html" %}
{% block description %}{{ _("Search results for %(sitename)s blog.", sitename=SITENAME|striptags|e) }}{% endblock %}
{% block title %}{{ _("Search") }} — {{ super() }}{% endblock %}
{% block content %}
<h1><?php echo '{{ _("Results for") }} "' . ($raw_q ?? '') . '":'; ?></h1>

<?php
if (isset($found) AND $found !== FALSE AND $found['status'] == 0) {

    if ($found['total_found'] != 0) {
        // Internationalized date (cf php intl module)
        $formatter = new IntlDateFormatter('{{ _("en") }}', IntlDateFormatter::SHORT, IntlDateFormatter::SHORT);
        $formatter->setPattern('E d MMM yyyy');

        foreach ($found['matches'] as &$document) {
            // Get date from timestamp
            $date = new DateTime();
            $date->setTimestamp($document['attrs']['published']);

            $category = $document['attrs']['category'];
            $category_url = $document['attrs']['category_url'];

            $authors = array_keys(json_decode($document['attrs']['authors'], true));
            $nb_authors = sizeof($authors);

            $tags = json_decode($document['attrs']['tags'], true);
            ?>

            <article class="article" itemscope itemtype="http://schema.org/BlogPosting">
                <a href="{{ SITEURL }}/<?php echo $document['attrs']['url']; ?>">
                    <h2 itemprop="headline"><?php echo $document['attrs']['title']; ?></h2>
                </a>
                <time datetime="<?php echo $date->format('c'); ?>" itemprop="datePublished"><?php echo $formatter->format($date); ?></time>
                &nbsp;—&nbsp;
                <?php
                // Authors
                foreach ($authors as $index => $author) {
                ?>
                    <span class="author-name" itemprop="author" itemscope itemtype="http://schema.org/Person">
                        <span itemprop="name"><?php echo $author; ?></span>
                    </span>
                <?php
                    if ($index + 1 < $nb_authors) {
                        echo ' &amp; ';
                    }
                }
                ?>
                <div class="summary" itemprop="abstract"><?php echo $document['attrs']['summary']; ?></div>
                {{ _("Category:") }}
                <span itemprop="articleSection">
                    <a href="{{ SITEURL }}/<?php echo $category_url; ?>" rel="category"><?php echo $category; ?></a>
                </span>
                <?php
                // Tags
                if (!empty($tags)) {
                    echo '{{ _("Tags:") }}';
                    foreach ($tags as $tag_name => &$tag_url) {
                    ?>
                    <span itemprop="keywords">
                        <a href="{{ SITEURL }}/<?php echo $tag_url; ?>" rel="tag"><?php echo $tag_name; ?></a>
                    </span>&nbsp;
                    <?php
                    }
                    unset($tag_url);
                }
                ?>
            </article>
            <?php
        }
        // Destruct ref on last element
        unset($document);

        // if !empty results
        echo '<p>' . $found['total_found'] . ' {{ _("article(s) found in") }} ' . $found['time'] .'s.</p>';
    } else {
        // No results
        echo '<p>{{ _("There are no results for your query.") }}</p>';
    }
} elseif (isset($found) AND !$found) {
    // Shinxsearch error
    echo '<p>{{ _("Error:") }} ' . $sphinx->getLastError() . '.</p>';
} else {
    // Bad/no parameters
    echo '<p>{{ _("Did you forget the query?") }}</p>';
}
?>
{% endblock content %}
