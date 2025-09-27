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
**Modified Repository:** https://github.com/wakitosh/MroongaSearch

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

### Configuration

No configuration is needed. Once installed, the database will be updated,
enabling full-text search.

### Uninstall

Simply uninstall this module to remove Mroonga settings from your database.
No additional work is needed.

## Notes

This version automatically uses MeCab tokenizer (TokenMecab) when available. If TokenMecab is not installed, it falls back to the default behavior safely.

### Technical note

MroongaSearch module changes the storage engine of the fulltext\_search table
of your Omeka S instance from InnoDB to Mroonga. This enables CJK-friendly fast
full-text search while it increases the size of the database.

If TokenMecab is available, the module sets a table COMMENT to specify the tokenizer like:
`COMMENT 'tokenizer "TokenMecab"'` so that Mroonga uses MeCab.
TokenMecab が利用可能な場合は、テーブルに `COMMENT 'tokenizer "TokenMecab"'` を付与して Mroonga に MeCab を使わせます。

## Integration with other modules / 他モジュール連携

This module does not require or expose a public PHP API for tokenization. Instead, it configures the database so that, when available, Mroonga uses MeCab (TokenMecab) for full-text indexing and querying. Other modules should treat morphological tokenization as an optional capability and implement graceful fallbacks.

本モジュールはトークナイズのための公開 PHP API を提供しません。代わりに、利用可能な環境では DB 側で Mroonga が MeCab（TokenMecab）を用いるよう設定します。他モジュールは「形態素トークナイズは任意機能」と捉え、常にフォールバック可能な実装にしてください。

### Recommended integration pattern / 推奨パターン

- Do not hard-require this module. Feature-detect availability instead.
- When you need standalone tokenization (e.g., generating example keywords in UI), implement your own helper that attempts morphological tokenization and falls back to a simple segmenter (regex/CJK-aware) when unavailable.
- For DB search, rely on the full-text index: if TokenMecab is active, Mroonga will tokenize appropriately at query time.

- 本モジュールを必須依存にしない。機能検出で可用性を判断。
- UI 側で独自にトークナイズが必要な場合（例：例示キーワード生成）、自前のヘルパを用意し、形態素解析に失敗・未導入時は正規表現等の簡易セグメンタにフォールバックする。
- DB 検索はフルテキストインデックスに任せる。TokenMecab が有効ならクエリ時に Mroonga が適切にトークナイズします。

### How to detect TokenMecab at DB level / DB レベルでの TokenMecab 判定

You may check the table comment of `fulltext_search` via `information_schema.TABLES`. If it contains `tokenizer "TokenMecab"`, MeCab is active for that table. Example SQL:

```sql
SELECT TABLE_COMMENT
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
	AND TABLE_NAME = 'fulltext_search';
```

`TABLE_COMMENT` に `tokenizer "TokenMecab"` が含まれていれば、有効です。環境によってはコメント情報が異なる場合があるため、判定に過度に依存しないでください。常にフォールバックを実装する前提を推奨します。

### Example: IiifSearchCarousel

IiifSearchCarousel module can leverage morphological tokens when available to produce better “For example” keywords while still working without MeCab/Mroonga. It should always try-and-fallback to keep the UI robust.

IiifSearchCarousel モジュールは、TokenMecab が利用可能な場合に形態素トークンを活用してより良い「例えば」表示を行い、未導入でもフォールバックして動作可能です。常に try-and-fallback の実装を推奨します。

## Changes from Original

- Verified and updated compatibility with Omeka S 4.x
- Automatic pre-install validation of Mroonga plugin state (ACTIVE check)
- Automatic engine switch for `fulltext_search` to `ENGINE=Mroonga`
- Automatic TokenMecab detection via temporary table COMMENT probe
- Safe FK handling around engine switch (drop before, restore on uninstall)
- Improved bilingual (EN/JA) actionable error messages
- Documentation updates and cleanup

## License

This modified version is released under the MIT License, same as the original.

Credits:
- Original: Kentaro Fukuchi — https://github.com/fukuchi/Omeka-S-module-mroonga-search
- First modification: Kazufumi Fukuda — https://github.com/fukudakz/Omeka-S-module-mroonga-search/
- Further modifications: Toshihito Waki — https://github.com/wakitosh/MroongaSearch

See the `LICENSE` file for details.
