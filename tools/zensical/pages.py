#!/usr/bin/env python3
"""Write the pages of the documentation site, as GitHub shows them, to the docs_dir of zensical.toml.

The site is doc/, with README.md, UPGRADE-2.0.md and CHANGELOG.md, which live at the root of the
repository. Zensical reads its pages from a single directory, which can't be the root, and runs
no hooks: this script copies them there, with the assets of the site. The links between the
pages are relative paths of the repository, like "doc/blob.md" or "../UPGRADE-2.0.md#errors":
they are rewritten to the pages of the site.

Usage, from the root of the repository: python3 tools/zensical/pages.py && zensical build
"""

import posixpath
import re
import shutil
import sys
from pathlib import Path

REPOSITORY_DIR = Path(__file__).resolve().parents[2]
# The docs_dir of zensical.toml: /var/ is ignored by git.
OUTPUT_DIR = REPOSITORY_DIR / 'var/docs'
ASSETS_DIR = Path(__file__).resolve().parent / 'assets'

# The files of the root of the repository published as pages, and their page on the site.
ROOT_PAGES = {
    'CHANGELOG.md': 'changelog.md',
    'README.md': 'index.md',
    'UPGRADE-2.0.md': 'upgrade-2.0.md',
}

# A relative link to a Markdown file: "](doc/blob.md)", "](../UPGRADE-2.0.md#errors)"...
MARKDOWN_LINK = re.compile(r'\]\((?P<path>(?![a-z]+:)[^)\s#]+\.md)(?P<fragment>#[^)\s]*)?\)')


def main() -> None:
    if OUTPUT_DIR.exists():
        shutil.rmtree(OUTPUT_DIR)
    shutil.copytree(REPOSITORY_DIR / 'doc', OUTPUT_DIR)
    shutil.copytree(ASSETS_DIR, OUTPUT_DIR / 'assets')

    pages = dict(ROOT_PAGES)
    for file in sorted((REPOSITORY_DIR / 'doc').rglob('*.md')):
        path = file.relative_to(REPOSITORY_DIR).as_posix()
        pages[path] = path.removeprefix('doc/')

    for path, site_path in pages.items():
        markdown = (REPOSITORY_DIR / path).read_text(encoding='utf-8')
        (OUTPUT_DIR / site_path).write_text(rewrite_links(markdown, path, site_path), encoding='utf-8')

    print(f'{len(pages)} pages written to {OUTPUT_DIR.relative_to(REPOSITORY_DIR)}/.', file=sys.stderr)


def rewrite_links(markdown: str, path: str, site_path: str) -> str:
    """Rewrite the links of the file path of the repository, published as site_path."""

    def rewrite(match: re.Match[str]) -> str:
        target = posixpath.normpath(posixpath.join(posixpath.dirname(path), match['path']))
        if target in ROOT_PAGES:
            target_site_path = ROOT_PAGES[target]
        elif target.startswith('doc/'):
            target_site_path = target.removeprefix('doc/')
        else:
            # Not a page: the strict build reports the link.
            return match[0]

        link = posixpath.relpath(target_site_path, posixpath.dirname(site_path) or '.')

        return '](' + link + (match['fragment'] or '') + ')'

    return MARKDOWN_LINK.sub(rewrite, markdown)


if __name__ == '__main__':
    main()
