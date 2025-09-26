# Mroonga Search for Omeka S (Modified Version)

This is a further modified version. The lineage is:
1) Original by Kentaro Fukuchi → 2) Modified by Kazufumi Fukuda → 3) Further modifications by Toshihito Waki.

## Original Module

**Original Author:** Kentaro Fukuchi
**Original Repository:** https://github.com/fukuchi/Omeka-S-module-mroonga-search
**Original License:** MIT License

**Modified by (first modification):** Kazufumi Fukuda
**Modified Repository:** https://github.com/fukudakz/Omeka-S-module-mroonga-search/

**Further modified by:** Toshihito Waki

## About This Modified Version

この修正版は、オリジナル版をベースに Omeka S v4 系での安定動作と自動化（環境検証・安全なインストール／アンインストール）を強化したものです。
This modified version maintains the core functionality while adding safety checks and automation for Omeka S v4.

この修正版は MeCab（TokenMecab）に対応しています。TokenMecab が利用可能な場合は自動的に有効化し、未導入の場合は従来のトークナイザに安全にフォールバックします。
This modified version supports MeCab (TokenMecab). When TokenMecab is available, it is enabled automatically; otherwise, the module safely falls back to the default tokenizer.

## Description

このモジュールは、Omeka S用のモジュールです。MySQLまたはMariaDBのMroongaプラグインを有効にすることで、CJK（日本語、中国語、韓国語）対応の全文検索を可能にします。
This module is for [Omeka S](https://omeka.org/s/) that enables CJK-ready full-text search by activating the [Mroonga](https://mroonga.org/) plugin of MySQL or MariaDB.

Omeka Sに標準で搭載されている全文検索機能は、データベースエンジン（MySQLまたはMariaDB）の制約により、CJKに対応していません。Mroongaプラグインはデータベースを拡張し、CJK対応の検索を実現します。このモジュールは、Omeka Sが使用するテーブル情報を変更することで、このプラグインを有効化するだけのシンプルなものです。
The default installation of the full-text search feature of the Omeka S is not CJK (Chinese, Japanese, Korean) ready because of the limitation of the database engine (MySQL or MariaDB). The Mroonga plugin extends the database to achieve CJK-ready search. This module simply activates this plugin by modifying the table information that used by Omeka S.

## Installation

### Preparation

First of all, **back up your database**. This module modifies the table schema,
and that may cause unrecoverable failure.

Before installing this module, install and configure the Mroonga plugin to
enable the Mroonga storage engine. For example, if you use MariaDB on Debian or
Ubuntu machine, install 'mariadb-plugin-mroonga' package. Please read the
[official document](https://mroonga.org/docs/install.html) for further
information.

Optionally, to enable MeCab support, install the MeCab tokenizer plugin for Groonga/Mroonga (e.g. `groonga-tokenizer-mecab`). If it is not installed, this module still works with the default tokenizer.
より良い日本語検索のために、可能であれば MeCab 用のトークナイザ（例: `groonga-tokenizer-mecab`）を導入してください。未導入でも本モジュールは従来のトークナイザで動作します。

### From ZIP

Download the latest release from this repository and unzip it in the
`modules` directory of Omeka S, then enable the module from the admin
dashboard. Read the
[user manual of Omeka S](https://omeka.org/s/docs/user-manual/modules/)
for further information.

### From GitHub

```bash
git clone https://github.com/fukudakz/Omeka-S-module-mroonga-search.git
cd Omeka-S-module-mroonga-search
# Rename the directory to MroongaSearch in the modules directory
```

### Configuration

No configuration is needed. Once installed, the database will be updated,
enabling full-text search.

### Uninstall

Simply uninstall this module to remove Mroonga settings from your database.
No additional work is needed.

## Notes

This module highly depends on the database structure of Omeka S 3.x and 4.x. If you are
upgrading Omeka S from 3.x to 4.x or later, we highly recommend you uninstall
this module **before upgrading**.

We have not heavily tested the Mroonga engine with large-sized data yet. For
an advanced full-text search, we recommend that you check the
[Solr module](https://omeka.org/s/modules/Solr/).

This version automatically uses MeCab tokenizer (TokenMecab) when available. If TokenMecab is not installed, it falls back to the default behavior safely.

### Technical note

MroongaSearch module changes the storage engine of the fulltext\_search table
of your Omeka S instance from InnoDB to Mroonga. This enables CJK-friendly fast
full-text search while it increases the size of the database.

If TokenMecab is available, the module sets a table COMMENT to specify the tokenizer like:
`COMMENT 'tokenizer "TokenMecab"'` so that Mroonga uses MeCab.
TokenMecab が利用可能な場合は、テーブルに `COMMENT 'tokenizer "TokenMecab"'` を付与して Mroonga に MeCab を使わせます。

## Changes from Original

- Verified and updated compatibility with Omeka S 4.x
- Automatic pre-install validation of Mroonga plugin state (ACTIVE check)
- Automatic engine switch for `fulltext_search` to `ENGINE=Mroonga`
- Automatic TokenMecab detection via temporary table COMMENT probe
- Safe FK handling around engine switch (drop before, restore on uninstall)
- Improved bilingual (EN/JA) actionable error messages
- Documentation updates and cleanup

## TODOs

* Enabling synonyms
* Support for additional parsers (MeCab, etc.)
* Performance optimizations

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This modified version is released under the MIT License, same as the original.

Credits:
- Original: Kentaro Fukuchi — https://github.com/fukuchi/Omeka-S-module-mroonga-search
- First modification: Kazufumi Fukuda — https://github.com/fukudakz/Omeka-S-module-mroonga-search/
- Further modifications: Toshihito Waki

See the `LICENSE` file for details.
