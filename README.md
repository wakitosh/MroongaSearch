# MroongaSearch for Omeka S

Enhanced, CJK-aware full-text search for Omeka S with safe fallbacks, manual engine switching, and an admin Diagnostics page. Works with or without the Mroonga storage engine; optionally uses MeCab for best Japanese tokenization.

## Module History

- **Original:** [Kentaro Fukuchi](https://github.com/fukuchi/Omeka-S-module-mroonga-search)
- **Modified:** [Kazufumi Fukuda](https://github.com/fukudakz/Omeka-S-module-mroonga-search/)
- **Enhanced:** [Toshihito Waki](https://github.com/wakitosh/MroongaSearch)

This version has been significantly enhanced to provide robust fallback mechanisms, safer installation/uninstallation, and improved search logic.

## Key Features

1.  Enhanced Japanese Full-Text Search (no Mroonga required)
    - Provides meaningful CJK search on vanilla Omeka S using smart fallbacks (LIKE for single-term CJK, strict BOOLEAN for others).
    - Enforces strict AND/OR logic consistently.

2.  Mroonga-Powered Search (when available)
    - If Mroonga is ACTIVE and `fulltext_search` is ENGINE=Mroonga ("effective Mroonga"), uses token-based matching; optionally MeCab for best accuracy.
    - Falls back automatically when not effective.

3.  Diagnostics admin page
    - Shows plugin status, table engine/comment, TokenMecab availability, FULLTEXT index info, resource counts (indexed vs actual), recent jobs (with Open/Log links).
    - Buttons to manually switch engines (to Mroonga / back to InnoDB) and to run segmented reindex jobs.

4.  Segmented reindex jobs with progress logging
    - Items only / Items + Item sets / Media only. Each logs page-by-page progress to the job log.

5.  Safer operations and fallbacks
    - Foreign keys handled during engine switches; FULLTEXT(title,text) ensured; single-term CJK on non-effective environments uses LIKE for recall.

## Installation

This module works without Mroonga, but installing Mroonga + MeCab yields the best results.

1) Install Mroonga (recommended)
- See https://mroonga.org/ for installation guidance.

2) Install groonga-tokenizer-mecab (optional, recommended)
- Enables morphological tokenization for best accuracy.

3) Install the module
- Unzip into `modules/MroongaSearch` and enable from Admin > Modules.

Note: Depending on your data size, indexing jobs may take time.

## Usage

- Full-text search parameters
    - `fulltext_search=...` and `logic=and|or`
    - Single-term CJK on non-effective Mroonga: falls back to LIKE for recall.

- Admin > Mroonga Search > Diagnostics
    - Check status and counts; switch engines manually; run segmented reindex jobs; inspect recent jobs with Open/Log.

## License

This module is licensed under the MIT License.

---

# MroongaSearch for Omeka S (日本語)

このモジュールは、Omeka Sの日本語全文検索機能を改善します。サーバー環境に応じて2つのモードで動作し、検索機能を強化します。

主な特長として、**Mroongaストレージエンジンがインストールされていない標準的なOmeka S環境でも、実用的な日本語全文検索を可能にします**。データベースの標準機能を用いることで、Omeka Sのデフォルトでは実現が難しい厳密な`AND`/`OR`検索を提供します。

Mroongaエンジンが利用可能な環境では、モジュールは自動的にMroongaを利用し、さらに高速な全文検索を実現します。

## モジュールの来歴

-   **オリジナル版:** [Kentaro Fukuchi](https://github.com/fukuchi/Omeka-S-module-mroonga-search)
-   **改訂版:** [Kazufumi Fukuda](https://github.com/fukudakz/Omeka-S-module-mroonga-search/)
-   **機能強化版:** [Toshihito Waki](https://github.com/wakitosh/MroongaSearch)

このバージョンは、フォールバック機能の強化、安全なインストール／アンインストール処理、検索ロジックの改善など、大幅な機能強化が施されています。

## 主な機能

1.  **日本語全文検索の強化 (Mroongaなし環境):**
    - 単語1件のCJKはLIKEで広く拾い、それ以外は厳密なBOOLEANで検索。
    - 厳密な AND/OR を適用。

2.  **Mroongaによる高速検索 (Mroongaあり環境):**
    - 「有効なMroonga」（Mroonga ACTIVE かつ テーブルENGINE=Mroonga）のとき、トークン一致で高精度検索（MeCab利用時はさらに高精度）。
    - 非有効時は自動フォールバック。

3.  **MeCab連携による高精度な検索（オプション）:**
    -   `groonga-tokenizer-mecab`が利用可能な環境では、MeCabをトークナイザとして利用し、より高精度な日本語検索を実現します。
    -   形態素解析によって日本語の単語の区切りを正確に認識できるようになります。
    -   MeCabが利用できない場合、モジュールは自動的にMroonga標準のトークナイザを使用します。

4.  **厳密な `AND` / `OR` 検索:**
    -   Omeka Sのデフォルトである自然言語検索とは異なり、このモジュールは厳密な検索条件を適用します。
    -   `AND`検索: 指定したすべてのキーワードを含む資料のみを返します。
    -   `OR`検索: 指定したキーワードのいずれかを含む資料を返します。

5.  **安全な運用（Diagnostics/手動切替/分割再インデックス）:**
    - 管理画面の Diagnostics で状態確認、エンジン切替（Mroonga/ InnoDB）、分割再インデックス（Items / Items+Item sets / Media）を実行。
    - エンジン切替時の外部キー対応、`FULLTEXT(title,text)` の保証。
    - Recent jobs に Open/Log リンク、件数に応じた再実行の警告確認。
    -   **インストール時:** Mroongaが有効かを確認し、安全にテーブルエンジンを変更します。問題が発生した場合は、正常な状態を維持しようと試みます。
    -   **アンインストール時:** `fulltext_search`テーブルを標準のInnoDBエンジンに戻します。これにより、モジュールを無効にしてもサイトが問題なく機能し続けます。

## インストール

このモジュールはMroongaなしでも動作しますが、より高いパフォーマンスを得るためにMroongaのインストールを推奨します。

1.  **Mroongaのインストール（推奨）:**
    -   CJK言語対応の高速な全文検索を利用するために、データベースにMroongaストレージエンジンをインストールしてください。
    -   導入手順については公式サイトを参照してください: [https://mroonga.org/](https://mroonga.org/)

2.  **MeCabトークナイザのインストール（オプション、推奨）:**
    -   より高精度な日本語の形態素解析のため、`groonga-tokenizer-mecab`のインストールを推奨します。

3.  **本モジュールのインストール:**
    -   モジュールを解凍し、`MroongaSearch`フォルダをOmeka Sの`modules`ディレクトリに配置します。
    -   Omeka Sの管理画面にログインし、「モジュール」セクションで「MroongaSearch」を有効化します。

注意: データ量によってはインデックス処理に時間がかかります。

これ以上の設定は不要です。モジュールが自動的に環境を検出し、適切な検索強化を適用します。

## 利用方法

全文検索の際、URLに`logic`パラメータを追加することで、検索ロジックを制御できます。

 - AND検索: `?fulltext_search=キーワード1+キーワード2&logic=and`
 - OR検索: `?fulltext_search=キーワード1+キーワード2&logic=or`
 - 単語1件CJK（Mroonga非有効時）はLIKEで広く拾います。

管理 > Mroonga Search > Diagnostics
 - ステータス・件数確認、エンジン切替（Mroonga / InnoDB）、分割再インデックス（Items / Items+Item sets / Media）、最近のジョブ（Open / Log）にアクセスできます。

`logic`パラメータを省略した場合、通常は`AND`検索として扱われます。

## ライセンス

このモジュールはMITライセンスの下で公開されています。


