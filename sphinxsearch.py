# -*- coding: utf-8 -*-
# This file is part of Shinxsearch for Pelican
#
# Copyright (C) 2017-2022 Ysard
#
# Shinxsearch for Pelican is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as
# published by the Free Software Foundation, either version 3 of the
# License, or (at your option) any later version.
#
# Shinxsearch for Pelican is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with Shinxsearch for Pelican.
# If not, see <http://www.gnu.org/licenses/>.

"""
Sphinx Search
-------------

This pelican plugin generates an xmlpipe2 formatted file that can be used by the
sphinxsearch indexer to index the entire site.

.. seealso:: https://sphinxsearch.com/docs/current/xmlpipe2.html
"""
# Standard imports
import os.path
import html
import zlib
# Custom imports
from bs4 import BeautifulSoup
from pelican import signals


class SphinxsearchXmlGenerator:
    def __init__(self, context, settings, path, theme, output_path, *args):

        self.output_path = output_path
        self.context = context
        self.siteurl = settings.get("SITEURL")
        self.dict_nodes = []

    def get_raw_text(self, html_content):
        """Clean the given html content and return clear text

        Strings are stripped, html entities are escaped as much as possible.

        :return: Clear text
        :rytpe: <str>
        """
        html_content = BeautifulSoup(html_content, "html.parser")

        # Todo: Suppress this ?
        # lots of entities are rencoded by html.escape func,
        # and Sphinsearch index can strip html entities
        cleaner_db = {"“": '"', "”": '"', "’": "'", "^": "&#94;", "¶": " "}

        # Get raw text from html & replace some entitites
        raw_text = list()
        for string in html_content.stripped_strings:
            for old, new in cleaner_db.items():
                string = string.replace(old, new)

            raw_text.append(string)

        # Escape all html entities to respect xml rules
        return html.escape(" ".join(raw_text))

    def build_data(self, document):
        """Get dictionary of formatted attributes related to the given document

        :return: Dictionary of data ready to be inserted in xml export.
            Attribute names as keys.
            Return None if the status of the page is not "published".
        :rtype: <dict>
        """
        # Only published documents are concerned (not drafts)
        if getattr(document, "status", None) != "published":
            return

        # Reconstruct url
        document_url = self.siteurl + "/" + document.url
        # Get timestamp
        document_time = str(document.date.timestamp())

        # There may be possible collisions, but it's the best I can think of.
        document_index = zlib.crc32(str(document_time + document_url).encode("utf-8"))

        return {
            "title": self.get_raw_text(document.title),
            "author": document.author,
            "authors": str({author.name: author.url for author in document.authors}),
            "category": document.category,
            "category_url": document.category.url,
            "url": document.url,
            "content": self.get_raw_text(document.content),
            "slug": document.slug,
            "time": document.date.timestamp(),
            "index": document_index,
            "summary": self.get_raw_text(document.summary),
            "tags": str(
                {tag.name: tag.url for tag in document.metadata.get("tags", tuple())}
            ),
        }

    def generate_output(self, writer):
        """Write `sphinxsearch.xml` file in Pelican output folder

        If there is no in-stream schema definition, settings from the
        configuration file will be used. Otherwise, stream settings take precedence.

        .. seealso:: https://sphinxsearch.com/docs/current/xmlpipe2.html

        .. note:: multi attributes handle only numeric ids.
            => not for static site without database.
        """
        path = os.path.join(self.output_path, "sphinxsearch.xml")

        documents = self.context["pages"] + self.context["articles"]

        for article in self.context["articles"]:
            documents += article.translations

        with open(path, "w", encoding="utf-8") as fd:
            # Add in-stream schema
            fd.write(
                """<?xml version="1.0" encoding="utf-8"?><sphinx:docset>
                <sphinx:schema>
                <sphinx:field name="content"/>
                <sphinx:field name="title"/>
                <sphinx:attr name="title" type="string"/>
                <sphinx:attr name="author" type="string"/>
                <sphinx:attr name="authors" type="json"/>
                <sphinx:attr name="category" type="string"/>
                <sphinx:attr name="category_url" type="string"/>
                <sphinx:attr name="url" type="string"/>
                <sphinx:attr name="summary" type="string"/>
                <sphinx:attr name="slug" type="string"/>
                <sphinx:attr name="published" type="timestamp"/>
                <sphinx:attr name="tags" type="json"/>
                </sphinx:schema>
                """
            )
            for document in documents:
                data = self.build_data(document)
                if not data:
                    continue
                fd.write(
                    '<sphinx:document id="{index}">'
                    "<title>{title}</title>"
                    "<author>{author}</author>"
                    "<authors>{authors}</authors>"
                    "<category>{category}</category>"
                    "<category_url>{category_url}</category_url>"
                    "<url>{url}</url>"
                    "<content><![CDATA[{content}]]></content>"
                    "<summary><![CDATA[{summary}]]></summary>"
                    "<slug>{slug}</slug>"
                    "<published>{time}</published>"
                    "<tags>{tags}</tags>"
                    "</sphinx:document>".format(**data)
                )
            fd.write("</sphinx:docset>")


def get_generators(generators):
    return SphinxsearchXmlGenerator


def register():
    signals.get_generators.connect(get_generators)
