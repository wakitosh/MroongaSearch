# Mroonga Search for Omeka S (Enhanced) / オメカS向け Mroonga 検索（改良版）

Lineage: 1) Original by Kentaro Fukuchi → 2) Modified by Kazufumi Fukuda → 3) Enhanced by Toshihito Waki.

---

## What this module does / 概要

- Switch `fulltext_search` to Mroonga engine for CJK-ready full-text.
	`fulltext_search` テーブルのストレージエンジンを Mroonga に切り替え、CJK 向け全文検索を有効化します。
- Auto-enable MeCab (TokenMecab) when available; safe fallback when not.
	TokenMecab が利用可能なら自動使用、未導入時は安全にフォールバックします。
- Strict AND/OR semantics for multi-term queries (UI: `logic` or `fulltext_logic`).
	複数語クエリに対し、厳密な AND/OR を提供します（UI パラメータ: `logic` または `fulltext_logic`）。
- Ensure `FULLTEXT(title, text)` index after engine changes.
	エンジン切替後に `FULLTEXT(title, text)` インデックスを自動保証します。
- Uninstall reverts to InnoDB (best-effort FK restore).
	アンインストール時は InnoDB に戻し、外部キーの復元を試みます。

## Requirements / 要件

- MariaDB 10.5+ or MySQL compatible with Mroonga
- Mroonga plugin installed and ACTIVE / Mroonga プラグインが導入・有効
- Optional: `groonga-tokenizer-mecab` for MeCab / 任意: `groonga-tokenizer-mecab`

Before enabling, back up your database. This module changes table engine and may trigger index rebuilds.
有効化前に必ずバックアップしてください。テーブルエンジン変更やインデックス再構築が発生します。

## Install / インストール

1) Install and activate Mroonga. On Debian/Ubuntu + MariaDB: `mariadb-plugin-mroonga`.
	 Mroonga を導入・有効化します（Debian/Ubuntu + MariaDB 例: `mariadb-plugin-mroonga`）。
2) Optional: install `groonga-tokenizer-mecab` for MeCab.
	 任意: MeCab 用 `groonga-tokenizer-mecab` を導入します。
3) Place this module under `modules/MroongaSearch` and enable it in admin.
	 本モジュールを `modules/MroongaSearch` に配置し、管理画面で有効化します。

On enable / 有効化時の動作:
- Check Mroonga plugin is ACTIVE.
	Mroonga の有効状態を検証します。
- Try `ALTER TABLE fulltext_search ENGINE=Mroonga COMMENT='table "ms_fulltext" tokenizer "TokenMecab"'` (falls back if TokenMecab is unavailable).
	`ALTER TABLE ... ENGINE=Mroonga` を実行（TokenMecab がなければ従来トークナイザを使用）。
- If ALTER fails, cleanup orphan Groonga objects, then DROP/CREATE with Mroonga and dispatch `IndexFulltextSearch`.
	ALTER 失敗時は孤児オブジェクトを清掃後、DROP/CREATE による再作成と再索引ジョブを実行します。
- Ensure `FULLTEXT(title, text)` exists to avoid 1191 errors.
	1191 回避のため `FULLTEXT(title, text)` を保証します。

## Uninstall / アンインストール

- Revert to InnoDB, restore FK on `owner_id` when applicable; may dispatch reindex.
	InnoDB に戻し、`owner_id` の外部キーを可能なら復元。必要に応じて再索引を実行します。

## Search behavior / 検索仕様

- Multi-term / 複数語:
	- AND: every token must match / 各トークンが必ずヒット
	- OR: any token may match / いずれかのトークンがヒット
- Single-term / 単語: defer to Omeka core’s natural fulltext.
	単語検索はコアの自然言語モードに委譲します。
- Fallback when Mroonga is unavailable / Mroonga 非使用時:
	- CJK: LIKE-based (single-character allowed) / CJK は LIKE（単文字許可）
	- non-CJK: BOOLEAN MODE with strict AND/OR / 非CJKは BOOLEAN MODE で厳密 AND/OR
- Avoid double filtering by diverting parameters so only one strict evaluation applies.
	パラメータの早期ガードにより二重適用を防ぎ、厳密評価を一度だけ適用します。

UI parameters / UI パラメータ:
- `fulltext_search` (query) / 検索語
- `logic` or `fulltext_logic` = `and` | `or`

## Operations / 運用

- Reindex job clears the table then refills by 100/page; hit counts rise as it proceeds.
	再索引ジョブは全削除→100件ページで再投入。開始直後はヒットが少なく、進むにつれ増加します。
- Incremental updates occur on resource save/delete; full reindex is not required for normal edits.
	保存/削除時に増分反映。通常編集にフル再索引は不要です。

## Troubleshooting / トラブルシュート

- 1191 FULLTEXT error: ensure `FULLTEXT(title, text)` exists (module auto-creates after engine changes).
	1191: `FULLTEXT(title, text)` の存在を確認（本モジュールが自動作成）。
- Mroonga not ACTIVE: install/enable plugin; the module refuses installation otherwise.
	Mroonga 未有効: プラグイン導入/有効化が必要。未満だとインストールを拒否します。
- OR behaves like AND: upgrade to this enhanced version; double filtering is fixed.
	OR が AND のようになる: 本改良版へ更新。二重適用を解消済みです。

## Integration notes / 連携

No public tokenization API; rely on DB features and keep fallbacks (e.g., IiifSearchCarousel).
公開トークナイズ API は提供しません。DB 機能検出＋フォールバックでの実装を推奨します。

DB hint: `information_schema.TABLES.TABLE_COMMENT` may include `tokenizer "TokenMecab"`. Don’t hard-depend.
DB ヒント: `TABLE_COMMENT` に `tokenizer "TokenMecab"` が含まれる場合がありますが、過度依存は非推奨です。

## Credits / クレジット

- Original: Kentaro Fukuchi — https://github.com/fukuchi/Omeka-S-module-mroonga-search (MIT)
- First modification: Kazufumi Fukuda — https://github.com/fukudakz/Omeka-S-module-mroonga-search/
- Enhancement: Toshihito Waki — https://github.com/wakitosh/MroongaSearch

## License / ライセンス

MIT License (same as original) / オリジナル同様 MIT ライセンス。`LICENSE` を参照。

# Mroonga Search for Omeka S (Enhanced)


この版は Omeka S 4.x 向けに、環境検証や安全なインストール/アンインストール、CJK 向け検索の厳密 AND/OR、フォールバック強化を行った改良版です。

## What this module does / 概要

- Switch `fulltext_search` to Mroonga engine for CJK-ready full-text.
- If available, enable MeCab tokenizer (TokenMecab) automatically; otherwise, fall back safely.
- Provide strict AND/OR semantics for multi-term queries (UI: `logic=and|or` or `fulltext_logic=...`).
- Guarantee the `FULLTEXT(title, text)` index after engine switch or table recreate.
- Revert to InnoDB on uninstall (with best-effort FK restore), leaving the site usable without Mroonga.

## Requirements / 要件

- MariaDB 10.5+ or MySQL compatible with Mroonga
- Mroonga plugin installed and ACTIVE
- Optional: `groonga-tokenizer-mecab` for MeCab (TokenMecab)

Before enabling the module, back up your database. This module changes table engine and may rebuild index content.

## Install / インストール

1) Ensure Mroonga is installed and ACTIVE. On Debian/Ubuntu with MariaDB, install `mariadb-plugin-mroonga`.
2) (Optional) Install MeCab tokenizer: `groonga-tokenizer-mecab`.
3) Put this module under `modules/MroongaSearch`, then enable it in Omeka S admin.

What happens on enable:
- The module checks that Mroonga plugin is ACTIVE.
- It tries `ALTER TABLE fulltext_search ENGINE=Mroonga COMMENT='table "ms_fulltext" tokenizer "TokenMecab"'` when TokenMecab is available (falls back to default tokenizer when not).
- If ALTER fails (e.g., orphan Groonga objects), it drops/recreates the table with Mroonga engine and dispatches Omeka’s `IndexFulltextSearch` job to rebuild content.
- It ensures a `FULLTEXT(title, text)` index exists (creates it if missing) to avoid 1191 errors.

## Uninstall / アンインストール

On uninstall, the module tries to revert `fulltext_search` back to InnoDB and restore the foreign key on `owner_id` when applicable. If the revert requires a rebuild, it will dispatch the `IndexFulltextSearch` job.

## Search behavior / 検索仕様

- Multi-term queries:
	- AND: every token must match (strict intersection)
	- OR: any token may match (strict union)
- Single-term queries: defer to Omeka core’s behavior for natural fulltext matching.
- Fallback when Mroonga is not available:
	- CJK terms: LIKE-based matching (includes single-character allowance)
	- non-CJK: BOOLEAN MODE with strict AND/OR
- The module avoids double filtering by diverting parameters internally so only one strict evaluation path applies.

UI parameters accepted:
- `fulltext_search` (query string)
- `logic` or `fulltext_logic` = `and` | `or`

## Operations / 運用メモ

- Reindex job: Omeka’s `IndexFulltextSearch` clears `fulltext_search` and refills in pages of 100. Immediately after starting, hit counts are low and increase as the job progresses.
- Incremental updates: On resource save/delete, Omeka updates `fulltext_search` automatically; full reindex is not required for regular edits.

## Troubleshooting / トラブルシュート

- SQLSTATE[HY000] 1191 (FULLTEXT index required): Ensure `FULLTEXT(title, text)` exists; this module auto-creates it after engine changes.
- Mroonga plugin not ACTIVE: The module will refuse to install; install/enable the plugin and try again.
- OR behaving like AND (few results): Ensure you are on this enhanced version; the module prevents core’s natural-mode double application and applies strict OR correctly.

## Integration notes / 連携メモ

This module doesn’t export a public tokenization API. Feature-detect at DB level if needed, and always keep a fallback path in dependent modules (e.g., IiifSearchCarousel).

DB-level TokenMecab hint: `information_schema.TABLES.TABLE_COMMENT` may contain `tokenizer "TokenMecab"`. Don’t rely on this alone; always implement fallbacks.

## Credits / クレジット

- Original: Kentaro Fukuchi — https://github.com/fukuchi/Omeka-S-module-mroonga-search (MIT)
- First modification: Kazufumi Fukuda — https://github.com/fukudakz/Omeka-S-module-mroonga-search/
- Enhancement: Toshihito Waki — https://github.com/wakitosh/MroongaSearch

## License

MIT License (same as original). See `LICENSE`.
