# Changelog / 変更履歴

All notable changes to this project will be documented in this file.
本ファイルはプロジェクトの重要な変更点を記録します。

The format is based on Keep a Changelog and this project adheres to Semantic Versioning where possible.
記法は Keep a Changelog に準拠し、可能な限り Semantic Versioning を採用しています。

## [4.2.0] - 2025-10-02

### Added
- **Admin Diagnostics Page**: A central hub to monitor Mroonga status, manage the search engine, and run indexing jobs. It shows:
    - Plugin status, table engine/comment, and TokenMecab availability.
    - "Effective Mroonga" status (Plugin ACTIVE + Engine=Mroonga).
    - `FULLTEXT` index details.
    - Indexed resource counts vs. actual database totals.
    - A list of recent indexing-related jobs with direct links to job details and logs.
- **Manual Engine Switching**: Buttons on the Diagnostics page to safely switch the `fulltext_search` table between `Mroonga` and `InnoDB`.
- **Segmented Re-indexing**: Manually trigger re-indexing jobs for "Items only," "Items + Item Sets," or "Media only" to minimize search disruption.
- **Dynamic Confirmations**: JavaScript confirmation dialogs on "Run" buttons that warn users when re-indexing a large number of resources.
- **Developer Documentation**: A new section in `README.md` explaining the technical details of search behavior and automatic engine management.

### Changed
- **Consolidated Admin Menu**: The admin navigation now has a single "Mroonga Search" link pointing to the Diagnostics page.
- **Manual Re-index Workflow**: Switching the engine no longer automatically triggers a re-index. Users are now instructed to manually run the desired re-indexing job from the Diagnostics page.
- **UI/UX**:
    - The Diagnostics page uses responsive tables (Tablesaw) for better viewing on small screens.
    - Long class names in the "Recent Jobs" table now wrap correctly.
    - "Open" and "Log" buttons for jobs are aligned horizontally.
- **Search Behavior Logic**: Search listeners now check for "Effective Mroonga" to determine whether to use Mroonga-specific features or the InnoDB fallback.
- **README Overhaul**: The `README.md` file was completely rewritten to reflect the new features, clarify installation, and provide detailed usage instructions in both English and Japanese.

### Fixed
- **Job Links**: The "Open" link for a job now correctly points to the job details page, resolving a 404 error. Links are hidden if a job ID is invalid.
- **DateTime Display**: Job start/end times in the "Recent Jobs" table are now correctly formatted as strings and respect the Omeka S site's timezone.
- **Diagnostics Page Errors**: Resolved several internal errors related to template path resolution, `lexical $this` in closures, and database transactions during the TokenMecab probe.

### Notes
- The difference in search results for single CJK terms between Mroonga (token-based) and InnoDB (LIKE-based) is intentional. Mroonga provides higher precision, while the fallback offers broader recall.

---
### 追加
- **管理者向け診断ページ**: Mroonga の状態監視、検索エンジンの管理、インデックスジョブの実行を中央管理するハブ機能。以下の情報を表示します:
    - プラグインの状態、テーブルのエンジン/コメント、TokenMecab の可用性
    - 「有効なMroonga」の状態（プラグインがACTIVE + エンジンがMroonga）
    - `FULLTEXT` インデックスの詳細
    - インデックス済みリソース数とデータベースの実際の総数との比較
    - 最近のインデックス関連ジョブの一覧（ジョブ詳細とログへの直接リンク付き）
- **手動エンジン切替**: 診断ページ上のボタンで `fulltext_search` テーブルのエンジンを `Mroonga` と `InnoDB` の間で安全に切り替え。
- **分割再インデックス**: 「アイテムのみ」「アイテム＋アイテムセット」「メディアのみ」の再インデックスジョブを手動で実行し、検索の中断を最小化。
- **動的確認ダイアログ**: 大量のリソースを再インデックスする際に警告するJavaScriptの確認ダイアログを「実行」ボタンに設置。
- **開発者向けドキュメント**: `README.md` に検索の挙動やエンジンの自動管理に関する技術的な詳細を説明するセクションを新設。

### 変更
- **集約された管理メニュー**: 管理画面のナビゲーションを「Mroonga Search」に一本化し、診断ページにリンク。
- **手動再インデックスのワークフロー**: エンジンを切り替えても自動で再インデックスが実行されなくなりました。ユーザーは診断ページから手動で目的の再インデックスジョブを実行する必要があります。
- **UI/UX**:
    - 診断ページでレスポンシブテーブル（Tablesaw）を使用し、小画面での閲覧性を向上。
    - 「最近のジョブ」テーブルで長いクラス名が正しく折り返されるように修正。
    - ジョブの「開く」「ログ」ボタンを水平に配置。
- **検索挙動のロジック**: 検索リスナーが「有効なMroonga」の状態をチェックし、Mroonga固有の機能を使うかInnoDBのフォールバックを使うかを決定するように変更。
- **READMEの全面改訂**: `README.md` を全面的に書き直し、新機能を反映させ、インストール方法を明確化し、日英両方で詳細な使い方を説明。

### 修正
- **ジョブリンク**: ジョブの「開く」リンクが正しくジョブ詳細ページを指すように修正し、404エラーを解決。ジョブIDが無効な場合はリンクを非表示に。
- **日時表示**: 「最近のジョブ」テーブルの開始/終了時刻が正しく文字列としてフォーマットされ、Omeka Sサイトのタイムゾーンを尊重するように修正。
- **診断ページのエラー**: テンプレートパスの解決、クロージャ内での `lexical $this`、TokenMecabの調査中のデータベーストランザクションに関連する複数の内部エラーを解決。

### 注記
- 単一のCJK単語に対するMroonga（トークンベース）とInnoDB（LIKEベース）の検索結果の違いは意図的なものです。Mroongaはより高い精度を、フォールバックはより広い再現率を提供します。

## [4.1.0] - 2025-09-29

### Added
- Ensure FULLTEXT(title, text) index after engine switch or table recreation to prevent SQLSTATE[HY000] 1191 errors.
- エンジン切替や再作成後に `FULLTEXT(title, text)` を自動保証し、1191 エラーを防止。
- Early-guard to divert parameters so that strict search is applied once (prevents core natural-mode from double-applying with module logic).
- 早期ガードでパラメータを振り分け、厳密検索を一度だけ適用（コア自然モードとの二重適用を防止）。
- README overhaul with install/uninstall behavior, AND/OR rules, fallbacks, and troubleshooting.
- README を刷新し、インストール/アンインストール、AND/OR 仕様、フォールバック、トラブルシュートを明記。

### Changed
- Engine reconciliation on bootstrap: if Mroonga plugin is ACTIVE, automatically switch `fulltext_search` to Mroonga (with pinned Groonga table name `ms_fulltext` and TokenMecab in COMMENT when available). If not ACTIVE, revert to InnoDB to keep the site functional.
- ブート時のエンジン整合性処理: Mroonga が ACTIVE なら自動で Mroonga に切替（Groonga 論理テーブル名 `ms_fulltext` を固定し、TokenMecab 可用時は COMMENT 指定）。ACTIVE でない場合は InnoDB に戻してサイトの可用性を維持。
- Safer handling for orphan Groonga objects during failed ALTER: attempt cleanup, then DROP/CREATE with Mroonga and dispatch reindex.
- ALTER 失敗時の孤児オブジェクトを安全に清掃し、DROP/CREATE＋再索引を実施。

### Fixed
- OR search returning only the smaller term due to double filtering: now the module avoids applying core natural-mode and module strict OR together; union results are correctly returned.
- OR 検索が二重適用で少数側のみ返す問題を修正。コア自然モードと厳密 OR の併用を避け、和集合を正しく返す。

### Notes
- Reindex job (`Omeka\Job\IndexFulltextSearch`) deletes all rows and refills in pages of 100. Immediately after starting a reindex, hit counts are expected to be low and increase as indexing progresses.
- 再索引ジョブは全削除→100件ページで再投入。開始直後はヒットが少なく、進行に伴い増加します。

## [4.0.3] - 2025-09-28

### Added
- Support strict OR search for multi-term queries when `logic=or` (or `fulltext_logic=or`) is specified. The module now builds `MATCH>0` clauses combined with OR, meaning results that match any of the tokens are returned.
- `logic=or`（または `fulltext_logic=or`）指定時、複数語の厳密 OR をサポート。各トークンの `MATCH>0` を OR 結合し、いずれかに一致する結果を返す。

### Notes
- When only one term is provided, the module defers to Omeka core's natural language fulltext behavior (no extra constraints), consistent with 4.0.2.
- 単語のみの場合はコアの自然言語フルテキストに委譲（追加制約なし）。4.0.2 と整合。

## [4.0.2] - 2025-09-28

### Changed
- Remove forced phrase search for a single continuous CJK term. For single-term queries, fall back to Omeka core's natural language fulltext behavior. Multi-term queries continue to be enforced as AND (each token must match), which aligns with the module's intent.
- 連続 CJK 単語でのフレーズ強制を撤回。単語検索はコア自然モードへ委譲。複数語は AND（全トークン一致）を維持。

### Rationale
- Users expect a single CJK term like 「鯰」 or 「北野」 to behave the same as Omeka default fulltext. Enforcing a phrase could narrow results unexpectedly, especially with variations in tokenization.
- 単語検索は既定と同等の挙動が期待されるため。フレーズ強制は想定外の絞り込みを招く可能性があるため撤回。

## [4.0.1] - 2025-09-27

### Added
- Interoperability: Clarified and stabilized the tokenization helper contract so other modules (e.g., IiifSearchCarousel) can safely call morphological tokenization when Mroonga/TokenMecab is available, and seamlessly fall back when not.
- 連携性: 形態素トークナイズのヘルパ契約を明確化/安定化し、他モジュールが可用時に活用・不可用時にフォールバックできるように。

### Changed
- Minor documentation and comments for integration scenarios.
- 連携シナリオ向けのドキュメント/コメントを微修正。

### Compatibility
- No schema or behavior change required for existing installations. Works whether TokenMecab is installed or not (dependent modules must handle fallback).
- 既存インストールにスキーマ変更不要。TokenMecab の有無に関わらず動作（連携側はフォールバックを実装）。

## [4.0.0] - 2025-09-26

Repository: https://github.com/wakitosh/MroongaSearch

### Added
- Automatic detection of Mroonga plugin state at install. If Mroonga is not ACTIVE, installation cleanly aborts with a bilingual (EN/JA) actionable message and the module remains uninstalled.
- Automatic engine switch for `fulltext_search` to `ENGINE=Mroonga`, with tokenizer `TokenMecab` set via COMMENT when available.
- Safe handling of foreign key on `owner_id`: dropped before engine switch and recreated on uninstall when missing.
- Verified compatibility with Omeka S 4.x.
- Improved error messages and documentation for Mroonga/TokenMecab setup.
- インストール時に Mroonga の有効状態を自動検出。非 ACTIVE なら日英併記の具体的対処メッセージを出して中止（未インストールのまま）。
- `fulltext_search` を `ENGINE=Mroonga` に自動切替。TokenMecab 可用時は COMMENT でトークナイザ指定。
- エンジン切替前に `owner_id` の外部キーを一時的に除去し、アンインストール時に再作成。
- Omeka S 4.x との互換性確認。
- Mroonga/TokenMecab セットアップのエラーメッセージと文書を改善。

### Changed
- Install sequence reordered to validate environment first, then write settings, then switch engine. Prevents partial settings writes on failure.
- Codebase cleaned up to follow Omeka S module style (spacing, braces, comments).
- インストール順序を見直し、環境検証→設定書込→エンジン切替の順に。失敗時の中途半端な状態を防止。
- コーディングスタイルを Omeka S モジュールに合わせて調整。

### Fixed
- Prevent fatal errors caused by accidental top-level code execution during module bootstrap.
- ブートストラップ時のトップレベル実行による致命的エラーを防止。

