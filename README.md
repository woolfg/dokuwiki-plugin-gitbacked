# gitbacked Plugin for DokuWiki

## :green_heart: Contributions welcome :green_heart:

You want to support Open Source, even if you are new to the game?
Feel free to grab an issue:

- [Smaller issues, also well suited for newcomers](https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues?q=is%3Aissue+is%3Aopen+label%3Acontributionwelcome)
- [Feature requests and other cool ideas](https://github.com/woolfg/dokuwiki-plugin-gitbacked/issues?q=is%3Aissue+is%3Aopen+label%3A%22feature+request%22)

If you have encountered a problem, you have a good idea, or just have a question, please, create a new issue.

## gitbacked Plugin for DokuWiki

Store/Sync pages and media files in a git repository

All documentation for this plugin can be found at
http://www.dokuwiki.org/plugin:gitbacked

If you install this plugin manually, make sure it is installed in
`lib/plugins/gitbacked/` - if the folder is called differently it
will not work!

Please refer to http://www.dokuwiki.org/plugins for additional info
on how to install plugins in DokuWiki.

## Release Management

- This plugin is provided as released DokuWiki installable ZIP packages with detailed release notes  
  via this repos [Release](https://github.com/woolfg/dokuwiki-plugin-gitbacked/releases) page.
- The name of a release is identical to the `date` property in `plugin.info.txt` of that release.
- Releases are built by the `build_release.yml` GitHub Action workflow of this project.
- A release build is triggered by applying a tag with name '**v**YYYY-MM-DD' to the corresponding most recent commit of this release.
- The release workflow is not triggered, if:
  - The release tag is not of format `v[0-9]+-[0-9]+-[0-9]+`
- The release workflow is failing and no release will be created, if:
  - The release version after the 'v'-prefix does not match the `date` property in file `plugin.info.txt`
  - The `CHANGELOG.md` does not contain a line of format '# [YYYY-MM-DD]' matching the release version
  - The `CHANGELOG.md` does not contain an appropriate compare link versus the previous release version at the end of the `CHANGELOG.md` file
- The release notes have to be maintained manually in `CHANGELOG.md` - further details can be found in the comment section within `CHANGELOG.md`

## Maintainers

- [@mhoffrog (Markus Hoffrogge)](https://github.com/mhoffrog)
- [@woolfg (Wolfgang Gassler)](https://github.com/woolfg)

## License

This plugin is licensed under GPLv2, see [LICENSE](LICENSE).

See the [COPYING](https://github.com/splitbrain/dokuwiki/blob/master/COPYING) file in your DokuWiki folder for details

