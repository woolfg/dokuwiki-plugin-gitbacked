# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

<!-- Format restrictions - see https://common-changelog.org and https://keepachangelog.com/ for details -->
<!-- Each Release must start with a line for the release version of exactly this format: ## [version] -->
<!-- The subsequent comment lines start with a space - not to irritate the release scripts parser!
 ## [yyyy-mm-dd]
 <empty line> - optional sub sections may follow like:
 ### Added:
 - This feature was added
 <empty line>
 ### Changed:
 - This feature was changed
 <empty line>
 ### Removed:
 - This feature was removed
 <empty line>
 ### Fixed:
 - This issue was fixed
 <empty line>
 <empty line> - next line is the starting of the previous release
 ## [yyyy-mm-dd]
 <empty line>
 <...>
 !!! In addition the compare URL links are to be maintained at the end of this CHANGELOG.md as follows.
     These links provide direct access to the GitHub compare vs. the previous release.
     The particular link of a released version will be copied to the release notes of a release accordingly.
     At the end of this file appropriate compare links have to be maintained for each release version in format:
 
  +-current release version
  |
  |            +-URL to this repo               previous release version tag-+            +-current release version tag
  |            |                                                             |            |
 [yyyy-mm-dd]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/compare/vYYYY-MM-DD..vYYYY-MM-DD
-->
<!--
## [Unreleased]

### Added
- TBD

### Changed
- TBD

### Deprecated
- TBD

###	Removed
- TBD

### Fixed
- TBD

###	Security
- TBD
-->

## [Unreleased]

### Changed
- TBD


## [2023-05-07]

### Fixed
- Deprecation warnings raised on `action/editcommit.php` - fixes [#86]


## [2023-03-07]

### Changed
- Allow absolute path in `'repoPath'` and/or `'repoWorkDir'` - implements [#80]
- `'repoWorkDir'` is configured empty by default now
- `--work-tree` option is ommited, if `'repoWorkDir'` is empty - addressing [#79]

### Fixed
- Cyrillic commit messages not being corrupted anymore - fixes [#82]


## [2022-02-06]

### Changed
- Created LICENSE file and removed corresponding text from the README.md - implements [#67]
- Use DokuWiki's user name & email address as commit author - implements [#63], [#66]
  - Updated default setting for `$conf['addParams']` to apply DokuWiki user name as commit author and DokuWiki user eMail as eMail.
  - If DokuWiki user eMail is empty, then the eMail assigned to the commit will be empty as well.
- Updated README.md:
  - Added a link to the referred COPYING license file originally hosted on the DokuWiki master branch to simplify a probable lookup.
  - Issues linked on startpage, motivate people to contribute

### Fixed
- Allow empty commits - fixes [#39]


## [2022-01-20]

### Fixed
- Fix for compatibility to PHP versions <7.4 - was introduced by previous release - fixes [#69]


## [2021-03-19]

### Added
- Extended to send error messages to a configurable eMail address - implements [#53]
- Added config `'emailAddressOnError'`
- Added config `'notifyByMailOnSuccess'`
- Added localizations for error messages
- Added eMail templates for mail notifications
- German translations added


## [2016-08-14]

### Changed
- Updated last change date to current date - fix [#38]

### Fixed
- Adjusted method signatures to match parent in action/editcommit.php
- Corrected method signature for php7-compatibility in action/editcommit.php


## [2015-10-03]

### Added
- Allow name and mail user variables in addParams.
- Add an option for customizing git working tree
- Added setting ignorePaths to ignore specified paths in add/commit-process

### Changed
- Use Markdown for the GitHub README.
- Update plugin date and URL, added Carsten Teibes as author
- Pull latest git php library (0.1.4)
- Allow to set the path to the git binary - implements [#8]
- Use relative path for Git.php and `$conf['tempdir']` for temp file.
- Coding compliance change: move handle_periodic_pull down, together with other "handle"s.

### Fixed
- Fix passing additional arguments to git binary
- Fix lang typos.
- Coding compliance change, tabs to spaces, fix typos.
- dokuwiki Farm fix


## [2012-10-31]

### Added
- Initial release

### Comments
- The release name complies with the date property of plugin.info.txt
- The recent commit within this release is [2dbc1a5](https://github.com/woolfg/dokuwiki-plugin-gitbacked/commit/2dbc1a5564516b801dbda239b68152edb5be0303) of 13-Nov-2012

<!--
## []

### NeverReleased
- This is just a dummy placeholder to make the parser of GHCICD/release-notes-from-changelog@v1 happy!
-->

[Unreleased]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/compare/v2023-05-07..HEAD
[2023-05-07]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/compare/v2023-03-07..v2023-05-07
[2023-03-07]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/compare/v2022-02-06..v2023-03-07
[2022-02-06]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/compare/v2022-01-20..v2022-02-06
[2022-01-20]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/compare/v2021-03-19..v2022-01-20
[2021-03-19]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/compare/v2016-08-14..v2021-03-19
[2016-08-14]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/compare/v2015-10-03..v2016-08-14
[2015-10-03]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/compare/v2012-10-31..v2015-10-03
[2012-10-31]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/releases/tag/v2012-10-31
[#86]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/86
[#82]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/82
[#80]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/80
[#79]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/79
[#69]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/69
[#67]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/67
[#66]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/66
[#63]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/63
[#53]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/53
[#39]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/39
[#38]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/38
[#8]: https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues/8
