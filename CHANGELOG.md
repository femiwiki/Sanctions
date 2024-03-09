# Changelog

Versions and bullets are arranged chronologically from latest to oldest.

## v1.2.3

- Fixed 50X errors. (https://github.com/femiwiki/Sanctions/issues/235)

## v1.2.2

- Fixed the panic of the auto-execution. (https://github.com/femiwiki/Sanctions/issues/223)

## v1.2.1

- Fixed the bug that topic pages were not opening. (https://github.com/femiwiki/Sanctions/issues/216)

## v1.2.0

- The expired sanctions required explicit execution. It doesn't now. (https://github.com/femiwiki/Sanctions/issues/5)
- The immediately rejected sanctions required explicit execution. It doesn't now. (https://github.com/femiwiki/Sanctions/issues/164)
- The notifications which are sent by the sanction bot was blocked, are now enabled.
- The wikitext format option of StructuredDiscussions is now supported.
- The images of the templates which are auto generated are removed.
- Localisations update.

BUG FIXES:

- Auto conversion not would be done if the sanctions board not yet created. (https://github.com/femiwiki/Sanctions/pull/127)

## 1.1.6

Note: this version requires MediaWiki 1.36+. Earlier versions are no longer supported.
If you still use those versions of MediaWiki, please use REL1_35 branch instead of this release.

- Localisations update.

ENHANCEMENTS:

- Localisation updates from https://translatewiki.net.

## 1.1.5

- Fix PhanUndeclaredMethod

## 1.1.4

- Improve CI build

## v1.1.3

ENHANCEMENTS:

- Notify when a sanction against the user is proposed

BUG FIXES:

- Use AutoloadNamespaces to register the extension.
