# InsureHub — Cross-Source Database Mapping (Research)

Generated 2026-06-30. Read-only research. No schema is proposed here.

Three sources reviewed:

1. **Access export** — `Access Database/Database/*.xlsx` (legacy MS Access; multiple versioned exports of the same tables)
2. **Strapi v3 SQL dump** — `Database Agent - Dbeaver/dump-insurehub-strapi-next-202606141119.sql` (PostgreSQL `pg_dump`, COPY-style)
3. **Vue 3 frontend** — `frontend/src/` (Pinia setup-style stores, in-file mock data, plus per-page local interfaces for surfaces with no dedicated store)

The Access export is dominated by **policy operations + commission calculation** data. Strapi only ever modelled **agent on-boarding** (KYC, license, bank account, documents) and a marketing landing page — none of the customer/policy/commission domain. The Vue frontend re-models the **whole domain** from scratch (modern, normalized field names) — and the Access columns are the closest match for everything except agents.

---

## 1. Access Tables (latest version of each)

Latest version is the highest `(n)` suffix in the filename. Versions appear to be byte-identical re-exports (same `MD5`-equivalent size) for parameter tables — assume they represent the same data.

### 1.1 `Agent_para.xlsx` — Agent / Influencer master

- **File:** `Agent_para.xlsx` (only version; 392 rows, 44 cols)
- **PK candidate:** `Insure_Agent_Code` (8-char string, e.g. `AG200000`, `IN210239`)
- **FK candidates:** `Bank_name` → `BankName_Para`, `MLM_Upline` → self (`Insure_Agent_Code`), `Team` / `Team_No` → free text (no para table found)
- **Columns (selected; full list below):**

| # | Column | Inferred type | Sample |
|---|---|---|---|
| 0 | `Insure_Agent_Code` | varchar(8) PK | `AG200000` |
| 1 | `Team LV1` | varchar(7) | `Team1-1` |
| 2 | `Agent_Tax_ID` | varchar(13) | `5045047656811` |
| 3 | `Agent_Name_Thai` | varchar | `อินชัวร์ฮับ` |
| 4 | `Agent_Surname_Thai` | varchar | `โบรกเกอร์` |
| 5 | `Agent_Name_Eng` | varchar | `Insurehub` |
| 6 | `Agent_Surname_Eng` | varchar | `Broker` |
| 7 | `Gender` | char(1) | `F` |
| 8 | `Mobile_phone` | varchar | `944289590` |
| 9 | `Tel_Phone` | varchar (all-null in sample) | |
| 10 | `Address_no` | varchar | `55/72` |
| 11 | `Building_Floor` | varchar | `Almalink` |
| 12 | `Moo` | varchar | `8` |
| 13 | `Moo_ban` | varchar | `อิ่มอัมพร2` |
| 14 | `Soi` | varchar | `ราชพฤกษ์9` |
| 15 | `Road` | varchar | `ราชพฤกษ์` |
| 16 | `Kwang` | varchar | `บางเชือกหนัง` (sub-district) |
| 17 | `Khet` | varchar | `ตลิ่งชัน` (district) |
| 18 | `Province` | varchar | `กรุงเทพฯ` |
| 19 | `Zip_Code` | varchar(5) | `10170` |
| 20 | `Birth_date` | datetime | `1987-07-14` |
| 21 | `Date_Apply` | datetime | `2020-01-01` |
| 22 | `Line_ID` | varchar | `toonza8632` |
| 23 | `Email` | varchar | `Chutharat.chan@gmail.com` |
| 24 | `Email2` | varchar | |
| 25 | `Facebook_Name` | varchar | `Toonza barbie` |
| 26 | `LicenseLife_No` | varchar(10) | `6103003331` |
| 27 | `Exp_Life` | datetime | `2020-02-05` |
| 28 | `LicenseNon_Life_No` | varchar | `6204005509` |
| 29 | `Exp_Non_Life` | datetime | `2020-02-06` |
| 30 | `Team_No` | varchar | `Team5` |
| 31 | `TypeofInfuencer` | varchar(2-3) | `AG` / `IN` |
| 32 | `Bank_Account` | varchar | `749-2-49063-0` |
| 33 | `Bank_name` | varchar (FK→BankName_Para) | `กสิกรไทย` |
| 34 | `Bank_Account_name` | varchar | `กษิรา เทพสุรินทร์` |
| 35 | `MLM_Upline` | varchar(8) (FK self) | `IN200019` |
| 36 | `MLM_Upline2` | varchar | `0` |
| 37 | `Head_Status` | varchar | `Active` |
| 38 | `Source` | varchar | `TEST` / `In-House` |
| 39 | `Head Start Date` | varchar (dd/m/yyyy) | `19/9/2020` |
| 40 | `Grace period End` | varchar (dd/m/yyyy) | `31/1/2021` |
| 41 | `Team LV2` | varchar | |
| 42 | `Team` | varchar | `Team1` |
| 43 | `VAT_TYPE` | varchar(1) | `1` |

### 1.2 `App_Doc_Control.xlsx` — Per-application document-path index

- 3,853 rows, 29 cols. **PK:** `ID` (int, autoincrement). **FK:** `Application_code` → `Application`.
- All other columns are file-system paths (`O_path_*` = original network share path, `E_path_*` = local user copy). One row per Application; doc slots are columns: `ID`, `IDkid`, `Passport`, `CarReg`, `PolRE` (policy renewal), `Extpol` (existing policy), `slip` (payment slip), `oth1..oth6`.
- Sample paths: `Z:\IHOS ACCESS\Record_data\DOC\2025\202505\20250519\…` and `\\IN723\Insurehub_Drive\…`. **This is a denormalized join/lookup table — not a generic file-uploads table.**

### 1.3 `Application.xlsx` — Policy/Application transactions

- **Latest:** `Application(1).xlsx`. 515 rows, **140 cols**. **PK:** `Application_code` (varchar(11), e.g. `A2407230005`). Largest business table.
- **FKs:** `Client_Code` → `Client.Client_Code`; `Product_Code` → `Main_Product`; `INC_Code` → `Insure_company`; `Insure_Influencer_Code` → `Agent_para.Insure_Agent_Code`; `Rider1..5` → `Main_Product`; `Payment_method` → `Payment_method_para`; `Payment_InsComp_Status` → `Payment_InsComp_Status_para`; `Payment_InsComp_To` → `Payment_InsComp_to_para`; `Policy_Status` → `Policies_status_para`.
- Conceptually it is **the policy** — applications and issued policies share the row; lifecycle is tracked by `Policy_Status` + a series of date/amount columns.

Column groups (with key columns called out):

- **Identifiers:** `Application_code` (PK), `Client_Code`, `Policy_Number`, `Notion_No`
- **Dates:** `Create_date`, `App_date`, `Coverage_start`, `Coverage_End`, `Payment_Date`, `Rebate_Earn_Date`, `rebate_OV_date`, `Rebate_Rec_Date_AG`, `Period_Paid_End`, `Policy_End`, `FirstDue_instDate` (string!), `LastDue_instDate` (string!)
- **Year tracking:** `Act_Year` (int), `Policy_Year` (varchar)
- **Agent:** `Insure_Influencer_Code` (FK), `Internal_Note`, `U` (recorder username, e.g. `Sumontha`, `benyapa`)
- **Product:** `Product_Code`, `Product_Name`, `INC_Code`, `Rider1`…`Rider5`, `Ref_app_to`, `NewOrRenew`
- **Motor (always present, often null):** `Type_Driver`, `Type_Vehicle`, `Vehicle_Brand`, `Vehicle_model`, `License_no`, `Engine_No`, `chassis_No`, `Register_Year`, `No_Passenger`, `NoteCarAsset`
- **Premium:** `Main_Premium`, `Rider1..5_Premium`, `Premium`, `Duty_stamp`, `Vat`, `Total_Premium_Paid`, `Net_Cus_paid`
- **WHT:** `WHT_Status`, `WHT_Amt`
- **Beneficiaries:** `Beneficiary`, `Beneficiary_Relation` (and `2/3/4`)
- **Coverage:** `Coverage_amt`, `Coverage_start`, `Coverage_End`
- **Payment terms:** `Type_of_paid`, `Note_Type_of_paid`, `Finance_Company`, `FirstDue_inst`, `NextDue_inst`, `Installment_Term`, `Payment_method`, `Subsidise_from_AG`, `Front_End_Fee`, `Discount_Amount`, `Subsidise_to_Finance`, `CreditCard_Fee`
- **Insured asset (fire/property):** `Insured_CompName`, `Insured_Address`, `Insured_Aset_Buil_Cov`, `Insured_Aset_Fur_Cov`, `Insured_Aset_Stok_Cov`, `Insured_Aset_Other_Cov`, `Insured_Aset_Other_Detl`, `Insured_Aset_Note`, `Insured_Mobile_phone`
- **Commission (In-House):** `Main_Com_InH`, `Rider1..5_Com_InH`, `Main_ComAmt_InH`, `Rider1..5_ComAmt_InH`
- **Commission (Agent):** `Main_Com_AG`, `Rider1..5_Com_AG`, `Main_ComAmt_AG`, `Rider1..5_ComAmt_AG`
- **Payment status:** `Payment_Amount`, `Payment_InsComp_Status`, `Payment_Date`, `Payment_InsComp_To`, `Count Slip` (has spaces in column name), `Validate_Payment_Amount`
- **Rebate / override (commission settlement):** `Rebate_Status`, `Rebate_Earn_Date`, `OV_status`, `rebate_OV_date`, `Cal_Rebate_Amt`, `Cal_Rebate_OV`, `Act_Rebate_Amt`, `Act_Rebate_OV`, `Validate_Rebate_Amt`, `Validate_Rebate_OV`, `Rebate_Status_AG`, `Rebate_Rec_Date_AG`, `Cal_Rebate_Amt_AG`, `Act_Rebate_Amt_AG`, `Check_AG_Rebate`
- **Cancellation / refund:** `Cancel_Status`, `Refund_Premium`, `Refund_Vat`, `Refund_Total_Premium`, `Refund_Discount`, `Net_Refund_Amount`, `Refund_Rebate_Amt`, `Refund_Rebate_OV`
- **Lifecycle / status:** `Freelook_Status` (bool), `Policy_Status`, `Policy_Status_Note`, `Mailing_Add_by_Policy`, `Mailing_Date` (mixed-format string), `Mailing_Note`
- **Operator:** `U` (string), `ComRec_Check` (`Pending`, `Complete`)

### 1.4 `BankName_Para.xlsx` — Bank lookup

- 11 rows, 2 cols. **PK:** `ID1` (int). Single name column `Bank_name` (Thai).
- Samples: `กสิกรไทย`, `ไทยพาณิชย์`, `กรุงเทพ`.

### 1.5 `Client.xlsx` — Customer master

- **Latest:** `Client(2).xlsx`. 357 rows, 52 cols. **PKs:** `ID` (int, surrogate) and `Client_Code` (varchar(8), e.g. `C9901093`).
- **FKs (referenced from Application):** `Client_Code`.
- **Columns:**

| # | Column | Type | Note |
|---|---|---|---|
| 0 | `ID` | int | surrogate PK |
| 1 | `Client_Code` | varchar(8) | business PK |
| 2 | `Type_Cust` | varchar(1) | 1 = individual (sample), likely 2 = corporate |
| 3-5 | `Name_Title_Thai`, `Name_Thai`, `Surname_Thai` | varchar | |
| 6-8 | `Name_Title_Eng`, `Name_Eng`, `Surname_Eng` | varchar | |
| 9 | `Gender` | varchar | `ชาย`, `หญิง` (Thai text) |
| 10 | `National_ID` | varchar(13) | |
| 11 | `National_ID_ExpD` | datetime | |
| 12 | `Passport` | varchar(9) | |
| 13 | `Race` | varchar | `ไทย` |
| 14 | `Nationality` | varchar | `ไทย` (FK→`nation` is by-name not by-code in samples) |
| 15 | `Religion` | varchar | `พุทธ` (FK→`religion` by-name) |
| 16 | `Mobile_phone` | varchar(10) | |
| 17 | `Tel_Phone` | varchar | |
| 18-27 | Permanent address: `Per_Address_no`, `Per_Building_Floor`, `Per_Moo`, `Per_Moo_ban`, `Per_Soi`, `Per_Road`, `Per_Kwang`, `Per_Khet`, `Per_Province`, `Per_Zip_Code` | varchar | |
| 28-37 | Mailing address: `Mailing_Address_no`, `Mailing_Building_Floor`, `Mailing_Moo`, `Mailing_Moo_ban`, `Mailing_Soi`, `Mailing_Road`, `Mailing_Kwang`, `Mailing_Khet`, `Mailing_Province`, `Mailing_Zip_Code` | varchar | parallel to Per_* |
| 38 | `Birth_date` | datetime | |
| 39-42 | `Line_ID`, `Email1`, `Email2`, `Facebook_Name` | varchar | |
| 43-46 | `Occupation`, `Position_held`, `Occupation_Company`, `Cus_Annually_Income` | varchar | `Occupation` FK→`Occupation` table by-name |
| 47-51 | Corporate contact: `Contact_person_receive`, `Contact_person_receive_address`, `Contact_person`, `Contact_Mobile_phone`, `Contact_Email` | varchar | |

### 1.6 `Insure_company.xlsx` — Insurance carriers

- **Latest:** `Insure_company(3).xlsx`. 45 rows, 14 cols. **PK:** `INC_Code` (varchar(3), e.g. `AKN`, `AIA`, `BLA`); surrogate `ID` int also present.
- Columns: `ID`, `INC_Code`, `INC_Name_En`, `INC_Name_TH`, `INC_nickname`, `Company_Insure_Type` (`Life` | `Non-Life`), `Sub_Insurer_type`, `Company_insuretypeID` (int), `Status` (`Active` etc.), `Tax_ID` (varchar(13)), `Address_for_WH`, `OIC_InsureCom_Code` (varchar(4) e.g. `1003`), `Comp_insure_code` (e.g. `850-00906`), `bank_account_1`.

### 1.7 `Location.xlsx` — Thai address lookup

- **Latest:** `Location(3).xlsx`. **7,460 rows**, 6 cols. **PK:** `ID` (int). `Location Code` (varchar(6)) is a synthetic admin code.
- Columns: `ID`, `Location Code`, `province`, `amphur`, `district`, `zip`.
- This is the canonical Thai tambon → amphoe → province → ZIP reference. Note: `Client.xlsx` and `Agent_para.xlsx` store the *names* (Thai text) not the Location Code.

### 1.8 `Main_Product.xlsx` — Product master + commission rates

- **Latest:** `Main_Product(3).xlsx`. 894 rows, 52 cols. **PKs:** `Commission_Code` (varchar(13), e.g. `CMAET00010002`) and `Product_Code` (varchar(9), e.g. `PDAET0001`). `ID` is also present (surrogate).
- **FK:** `INC_Code` → `Insure_company`.
- Columns:

| Column | Type | Note |
|---|---|---|
| `ID` | int | |
| `Commission_Code` | varchar(13) | Per-rate version row PK |
| `Valid_start` / `Valid_End` | datetime | Effective-dated rate (sentinel end = 9999-01-01) |
| `Product_Code` | varchar(9) | |
| `Product_Name` | varchar | |
| `INC_Code` | varchar(3) FK | |
| `Insure_Type` | `Life` | `Non-Life` |
| `Company_Insure_Type` | int | |
| `Categories` | varchar | `การประกันภัยเบ็ดเตล็ด` etc. |
| `Sub_Categories` | varchar | `สุขภาพ`, `บำนาญ`, ... |
| `Sub_Categories2` | varchar | `Non-Motor` / `Motor` |
| `Main_Rider` | varchar | `Main` or rider type |
| `minAge`, `maxAge`, `minSumAssure`, `maxSumAssure`, `maxRAR`, `minRAR` | numeric (mostly null) | Eligibility / risk-assessment ratio |
| `ComCommission`, `ComCommission_2..10`, `ComCommission_11Up` | float | Commission % per policy year 1..11+ (carrier-side / "company commission") |
| `AgCommission`, `AgCommission_2..10`, `AgCommission_11Up` | float | Agent commission % per year |
| `InCommission`, `InCommission_2..10`, `InCommission_11Up` | float | Influencer commission % per year |

This is the **commission schedule** baked into the product. Critical for the new schema.

### 1.9 `MotorMarketGrouppara.xlsx` — Motor segmentation

- **Latest:** `MotorMarketGrouppara(3).xlsx`. 10 rows, 5 cols. **PK:** `VEHICLE_MARKET_GROUP` (varchar(2)).
- Columns: `ID`, `VEHICLE_MARKET_GROUP`, `Desc_Eng` (`Luxury`, `Market`, `High Sum`…), `Desc_Thai`, `Redbook Type` (`PS` = passenger sedan etc.)

### 1.10 `Motor_para.xlsx` — Vehicle catalogue (Thai Redbook)

- 32,664 rows, 36 cols. **PK candidate:** combination `SERIAL` + `EFFECT_DATE` (multiple rows per `SERIAL` for different years; row 1 has identical SERIAL to row 2 — `SERIAL` alone is NOT unique). `ID` int is surrogate.
- Columns include `Brand_CODE`, `MODEL_CODE`, `SUBMODEL_CODE`, `Vehicle_Brand`, `Vehicle_model`, `Vehicle_submodel`, `VH_YEAR_BEG`, `VH_YEAR_END`, `COVER_01_FLAG`, `COVER_PLUS_FLAG`, `VEHICLE_MARKET_GROUP` (FK→MotorMarketGroup), `SEDAN_TYPE`, `SELL_STAT`, `SUBMODEL_YEAR`, `EFFECT_DATE`, `SCALE`, `WEIGHT`, `CC`, `SEAT_NO`, `CHASSIS_NO_FORMAT`, `ENGINE_NO_FORMAT`, `STANDARD_SUMINS`, `STANDARD_THEFT`, `80_SUMINS` (column name starts with digit!), `CAR_GROUP`, `OLD_STD_SUMINS`, `OLD_STD_THEFT`, `CREATE_MODIFY_DATE`, `CREATE_ID`, `MODIFY_ID`, `MODIFY_TIME`, `MODEL_STATUS`, `rbm_serial`, `Redbook_Code`.
- Numerics in this table are stored as **strings** (`'1300.00'`, `'15108'`).

### 1.11 `Occupation.xlsx` — Occupation lookup

- **Latest:** `Occupation(3).xlsx`. 48 rows, 4 cols. **PK:** `occ_Code` (int). Columns: `occ_Type` (`Individual`, `Individual / Coporate` [sic]), `occ_Code`, `occ_des_th`, `occ_des_en`.

### 1.12 `PW_InsHub.xlsx` — Internal application user logins

- **Latest:** `PW_InsHub(3).xlsx`. **8 rows**, 9 cols. **PK:** `ID`. Columns: `ID`, `U` (username, e.g. `mac`, `Norapat`, `Jiratad`), `P` (cleartext password — **plaintext, e.g. `1234`, `mac`**), `TITLE`, `FNAME`, `LNAME`, `Roles` (`Super Admin`, `Admin` etc.), `Status` (`Active`), `DateCreate` (datetime; some rows have an obviously bogus year `1482-02-19`).
- **Security note:** this is the legacy MS Access internal login table. Passwords are stored in plaintext; the `1482` dates are pre-epoch garbage.

### 1.13 `Payment_InsComp_Status_para.xlsx`

- 3 rows, 2 cols. `ID` + `Payment_InsComp_Status_des`: `จ่ายครบแล้ว`, `ยังไม่จ่าย`, `จ่ายไม่ครบ`.

### 1.14 `Payment_InsComp_to_para.xlsx`

- 2 rows, 2 cols. `ID` + `Payment_InsComp_to_des`: `อินชัวร์ฮับ`, `บริษัทประกัน`.

### 1.15 `Payment_method_para.xlsx`

- 3 rows, 2 cols. `ID` + `payment_method_des`: `เงินสด`, `ตัดบัตร`, `เงินโอน`.

### 1.16 `Policies_status_para.xlsx`

- 11 rows, 3 cols. `ID` (int) PK; `Policies_status` (e.g. `รอพิจารณา`, `รอตรวจรถ`, `รอบัตรประชาชน`); `G_Policies_status` (grouped: `ต้องติดตาม`, `อนุมัติแล้ว`, etc.)

### 1.17 `nation.xlsx` — Country / nationality

- **Latest:** `nation(3).xlsx`. 242 rows, 7 cols. **PK:** `ID` int. Columns: `nation_id` (varchar(2), ISO-2), `country_id` (varchar(3), ISO-3), `nation_name_th`, `nation_name_en`, `country_name_th`, `country_name_en`.

### 1.18 `paidstatus_para.xlsx`

- 16 rows, 4 cols. `ID` PK; `G_paid_status` (`จ่ายครบ`, `จ่ายไม่ครบ`, …); `paid_status` (long descriptive label, e.g. `จ่ายยอดหักคอม-Insurehub`); `FinanceComp` (`INS`).

### 1.19 `prefix_para.xlsx`

- 244 rows, 5 cols. PK is composite (`Insured_Type_ID`, `Title_Code`). Columns: `Insured_Type` (`Corporate` / `Individual`), `Insured_Type_ID` (int), `Title_Code` (int), `Description_TH` (`บริษัท`, `นาย`, …), `Description_EN` (`Company`, `Mr.`, …).

### 1.20 `religion.xlsx`

- 11 rows, 3 cols. `religion_ID` PK, `religion_des_th`, `religion_des_en`.

### 1.21 Screenshot deck (`Access Screenshot.docx`)

Mostly image-only screenshots; extractable text is a short Thai outline of the Access UI tabs ("Tap 1…Tap 6"). Notable hints:

- Tab 1 has 5 main buttons: **(1) Search customer** (sub-buttons: load-for-new-policy, edit), **(2) Create customer**, **(3) Search policy** (sub-buttons: edit, upload docs, copy-for-renew, copy-for-new, delete — with "lock delete permission"), **(4) Create new policy**, **(5) Search group policies**.
- The endorsement add ("เพิ่มสลักหลังกรมธรรม์") is a distinct flow — implies an endorsement table exists in the original Access DB even if not in the Excel exports.
- No relationship diagram is recoverable from the docx — relationships have to be inferred from column naming + the report below.

---

## 2. Strapi business tables

The Strapi dump has **22 business tables** (mostly Strapi component back-tables for one main content type) and **13 internal tables**. The single business model is the *agent on-boarding profile* plus a CMS-managed *agent landing page*.

### 2.1 `users-permissions_user` — Agent + login (HYBRID)

(Strapi internal `users-permissions` plugin table EXTENDED with custom business columns.) **643 rows.**

| Column | Type | Strapi-default or custom |
|---|---|---|
| `id` | integer PK | default |
| `username` | varchar(255) | default (e.g. `in210076`, `ag200002`) |
| `email` | varchar(255) | default |
| `provider` | varchar(255) | default (`local`) |
| `password` | varchar(255) | default (bcrypt) |
| `resetPasswordToken` | varchar | default |
| `confirmationToken` | varchar | default |
| `confirmed`, `blocked` | boolean | default |
| `role` | integer FK→`users-permissions_role` | default |
| `created_by`, `updated_by` | integer | Strapi audit |
| `created_at`, `updated_at` | timestamptz | default |
| `agent_type` | varchar | **custom**: `in` (Influencer) / `ag` (Agent) — matches Access `TypeofInfuencer` |
| `agent_code` | bigint | **custom**: numeric agent code (76, 2, 240) — matches the numeric part of Access `Insure_Agent_Code` |
| `agent_year` | integer | **custom**: 20, 21, 22 — year of recruitment |
| `signup_type` | varchar | **custom**: `personal` |
| `upline` | integer | **custom**: parent agent id (FK self) |
| `agent_doc_status` | boolean | **custom** (all-null in sample) |
| `agent_doc_description` | text | **custom** (all-null in sample) |

The username pattern `in210076` decomposes as `<agent_type><agent_year><agent_code-zero-padded>` — i.e. `agent_type='in'`, `agent_year=21`, `agent_code=76`.

### 2.2 `users-permissions_user_components` — Polymorphic join

3,605 rows. Connects each user to up to 7 Strapi component sub-rows. Distinct `field` values observed in the dump:

| `field` value | Component table | Row count |
|---|---|---|
| `agent_info` | `components_agent_agent_infos` | 643 |
| `agent_license` | `components_agent_agent_licenses` | 470 |
| `agent_bank_account` | `components_agent_agent_bank_accounts` | 472 |
| `agent_license_life` | `components_agent_agent_licenses` (re-used) | 104 |
| `agent_invoice_address` | `components_agent_agent_invoice_addresses` | 642 |
| `agent_document_address` | `components_agent_agent_document_addresses` | 642 |
| `agent_dashboard` | `components_agent_agent_dashboards` | 632 |

Columns: `id`, `field`, `order`, `component_type` (table name), `component_id` (PK of that table), `users-permissions_user_id`.

### 2.3 `components_agent_agent_infos` — Agent personal info (643 rows)

| Column | Type |
|---|---|
| `id` | int |
| `firstname` | varchar |
| `lastname` | varchar |
| `juristic_name` | varchar |
| `branch` | varchar |
| `citizen_id` | bigint |
| `birthdate` | date |
| `phone_number` | varchar |
| `line_id` | varchar |
| `company_register_date` | date |
| `tax_id` | bigint |

Sample row: `1179 | สิริพัชร์ | เปรมัษเฐียร | | | 3100902187876 | 1977-04-06 | 0994365415 | primalinn | null | null`.

### 2.4 `components_agent_agent_licenses` — License (574 rows, used twice per user for life + non-life)

| Column | Type |
|---|---|
| `id` | int |
| `have_license` | boolean |
| `license_id` | varchar |
| `license_expiration_date` | date |

### 2.5 `components_agent_agent_bank_accounts` — Payout bank (472 rows)

| Column | Type |
|---|---|
| `id` | int |
| `issuing_bank` | varchar |
| `account_number` | varchar |

### 2.6 `components_agent_agent_invoice_addresses` — Billing address (643 rows)

`id`, `address` (text), `province`, `district`, `sub_district`, `postcode` — all varchar(255) except `address` (text).

### 2.7 `components_agent_agent_document_addresses` — Mailing/document address (642 rows)

Same columns as invoice address PLUS `same_as_invoice` boolean.

### 2.8 `components_agent_agent_doc_statuses` — Doc completion checklist (652 rows)

`id`, `completed` (boolean), `description` (text). Not joined to user via `users-permissions_user_components` — wired through `agent_dashboards` (next).

### 2.9 `components_agent_agent_dashboards` — Per-agent dashboard URLs (632 rows)

`id`, `income_url` (varchar), `job_url` (varchar), `expire_reminder_url` (varchar). Per-agent personalized links — likely Google Sheets / Drive URLs.

### 2.10 `agent_dashboards` — Singleton (1 row) — Strapi single-type

Strapi single-type body: `id`, `published_at`, `created_by`, `updated_by`, `created_at`, `updated_at`. Sub-component rows live in `agent_dashboards_components` (10 rows) which dispatches by `field`/`component_type` to:

- `components_agent_dashboard_agent_additional_infos` (7 rows: `title`, `short_description`, `url`)
- `components_agent_dashboard_agent_manuals` (1 row: `title`, `short_description`, `url`)
- `components_agent_dashboard_staff_contact_infos` (2 rows: `icon`, `label`, `url`)

This is the **shared global agent dashboard config**, not per-agent. (Per-agent links are in `components_agent_agent_dashboards`.)

### 2.11 `agent_landings` — Public marketing page (1 row + 8 components rows)

Dispatches to:

- `components_agent_landing_heroes` (1: `title`, `tagline`, `description`)
- `components_agent_landing_call_to_actions` (1: `title`, `description`)
- `components_agent_landing_faqs` (1: `tagline`, `title`, `description`) — and `_faq_contents` (2 rows: `title`, `description`) via `_faqs_components`
- `components_agent_landing_features` (1: `icon`, `title`, `description`) — and `_features_contents` (4 rows: `title`, `icon`, `description`) via `_features_components`
- `components_agent_landing_contacts` (4 rows: `label`, `value`, `icon`)

### 2.12 Internal Strapi tables (names + row counts only)

| Table | Rows |
|---|---|
| `public."users-permissions_permission"` | 297 |
| `public."users-permissions_role"` | 3 |
| `public."users-permissions_user_components"` | 3605 |
| `public.core_store` | 63 |
| `public.i18n_locales` | 1 |
| `public.strapi_administrator` | 3 |
| `public.strapi_permission` | 79 |
| `public.strapi_role` | 3 |
| `public.strapi_users_roles` | 3 |
| `public.strapi_webhooks` | 486 |
| `public.upload_file` | (rows not counted — body too large) |
| `public.upload_file_morph` | 407 |

**Key gap:** Strapi has **no** Customer, Carrier, Product, Policy, Application, or Commission tables. Everything below the agent-profile layer is missing from this dump.

---

## 3. Frontend entities (Vue 3 + Pinia)

### 3.1 `types/modules.ts`

Contains the **UI module catalogue only** — no domain interfaces. Defines `ModuleDef { key, number, routePath, routeName, icon, i18nKey, functions: string[], group }` and exports a `MODULES` array of 16 module descriptors used by the side-nav and `Dashboard.vue`. Module list (key → group → routePath):

| # | key | group | routePath |
|---|---|---|---|
| 1 | auth | core | /auth |
| 2 | tenant-settings | core | /settings |
| 3 | carriers | business | /carriers |
| 4 | products | business | /products |
| 5 | contracts | business | /contracts |
| 6 | agents | people | /agents |
| 7 | customers | people | /customers |
| 8 | policies | business | /policies |
| 9 | commission-engine | commission | /commissions/engine |
| 10 | commission-ledger | commission | /commissions/ledger |
| 11 | payouts | commission | /payouts |
| 12 | reports | reporting | /reports |
| 13 | notifications | platform | /notifications |
| 14 | platform-admin | platform | /admin |
| 15 | agent-support | support | /support |
| 16 | agent-operation-support | support | /ops |

`MODULE_GROUPS` lists 7 group keys with Thai labels (`ระบบหลัก`, `ข้อมูลธุรกิจ`, `บุคลากรและลูกค้า`, `ค่าคอมมิชชั่นและจ่ายเงิน`, `รายงานและแจ้งเตือน`, `แพลตฟอร์ม`, `ฝ่ายสนับสนุน`).

### 3.2 Pinia stores (all are **setup-style** — no `state: () => ({})`)

#### `stores/agents.ts` — `defineStore('agents', ...)`

State refs:
- `agents: ref<Agent[]>(...)` — seeded with a 4-level MLM tree (top-of-tree `a1` down to `l1` leaves)
- `links: ref<RecruitmentLink[]>([])`
- Computed: `topLevelAgents` (agents with `parentAgentId === null`)

**Interface `Agent`** (seed used `licenseNumber/Issuer/Expiry` only; the life/non-life split fields were added later as documented in the source comments):

```
id: string
agentCode: string
firstName: string
lastName: string
nickname: string
firstNameEn: string
lastNameEn: string
gender: Gender                            // 'male'|'female'|'other'|''
email: string
phone: string
lineId: string
idCard: string                            // 13-digit Thai ID
birthDate: string                         // BE date "25xx-mm-dd"
address: string                           // free-text street block
province: string
district: string                          // amphoe / khet
subDistrict: string                       // tambon / kwaeng
postcode: string
kind: AgentKind                           // 'individual' | 'corporate'
juristicName: string                      // when corporate
taxId: string
vatType: VatType                          // '' | 'none' | 'vat7' | 'wht1' | 'wht3' | 'wht5'
bank: AgentBank                           // { bankName, accountNo, accountName }
licenseNumber: string                     // legacy single
licenseIssuer: string                     // e.g. "คปภ."
licenseExpiry: string | null
licenseLifeNo: string                     // newer split
licenseLifeExpiry: string | null
licenseNonLifeNo: string
licenseNonLifeExpiry: string | null
parentAgentId: string | null              // self-FK for MLM tree
level: AgentLevel                         // 'l1'|'l2'|'l3'|'l4'|'l5'
commissionPct: number                     // overall %
joinedAt: string                          // BE date
notes: string
active: boolean
```

**Interface `RecruitmentLink`**: `id, agentId, token, generatedAt, clicks, signups, pendingSignups, revoked`.

#### `stores/customers.ts` — `defineStore('customers', ...)`

State refs:
- `customers: ref<Customer[]>(...)` — seeded
- `links: ref<CustomerReferralLink[]>(...)`
- Computed: `unassignedCustomers`

**Interface `Customer`** (large — combines registered + mailing + corporate + KYC):

```
id, customerCode
customerType: CustomerType                // 'individual'|'corporate'
titleTh, titleEn
firstName, lastName, nickname
firstNameEn, lastNameEn
juristicName, taxId                       // when corporate
idCard, nationalIdExpiry: string|null, passport, nationality, religion
birthDate, gender: Gender, maritalStatus: MaritalStatus    // 'single'|'married'|'divorced'|'widowed'
occupation, position, employerName, monthlyIncome: number
email, phone, lineId
address, district (sub-district/tambon), amphoe (district/khet), province, postcode
mailingSameAsRegistered: boolean
mailing: MailingAddress                   // { address, subDistrict, district, province, postcode }
contactPerson: CorporateContact           // { name, phone, email, position }
createdByAgentId: string | null
assignedAgentId: string | null
registeredAt
lastContact: string | null
notes
activePolicyCount, totalPolicyCount
kycDocs: KycDoc[]
assignmentHistory: AssignmentHistoryEntry[]
active: boolean
```

`KycDoc { id, type: KycDocType, fileName, uploadedAt, uploadedByAgentId, verified }`. `KycDocType ∈ 'idCard' | 'houseReg' | 'bankBook' | 'income' | 'medical' | 'photo' | 'signature' | 'other'`.

`AssignmentHistoryEntry { id, fromAgentId|null, toAgentId|null, reason, byUserId, at }`.

`CustomerReferralLink { id, agentId, productId: string|null, campaign, token, generatedAt, clicks, leads, policies, revoked }`.

#### `stores/policies.ts` — `defineStore('policies', ...)`

State refs:
- `policies: ref<Policy[]>(...)` — seeded with ~12 policies covering all statuses
- Computed: `totalsByStatus`

**Interface `Policy`** (the central business object):

```
id
quoteNo, applicationNo: string|null, policyNo: string|null     // one filled per lifecycle stage
customerId, productId, carrierId, writingAgentId               // FKs
coverage: number
annualPremium: number
premiumMode: 'monthly'|'quarterly'|'semiannual'|'annual'|'single'
quoteDate
effectiveDate: string|null
expiryDate: string|null
issueDate: string|null
nextPremiumDue: string|null
cancelDate: string|null
lapseDate: string|null
policyYear: number                                              // 1,2,3...
actYear: number                                                 // billing/commission year
newOrRenew: 'new'|'renew'
freelookActive: boolean
riders: Rider[]                                                 // { name, premium, notes }
beneficiaries: Beneficiary[]                                    // { name, relation, share }
motor: MotorDetails|null
property: PropertyDetails|null
status: PolicyStatus
notes
events: PolicyEvent[]                                           // ordered audit trail
payments: PolicyPayment[]
documents: PolicyDocument[]
```

`PolicyStatus ∈ 'quote' | 'application' | 'submitted' | 'issued' | 'active' | 'lapsed' | 'cancelled' | 'reinstated' | 'expired'`.

`PolicyEventType ∈ 'created' | 'convertedToApplication' | 'submittedToCarrier' | 'issued' | 'premiumPaid' | 'renewed' | 'lapsed' | 'cancelled' | 'reinstated' | 'detailsUpdated' | 'documentUploaded'`.

`PolicyEvent { id, policyId, type, at, byUserId, payload: Record<string, string|number|null> }`.

`PolicyPayment { id, policyId, paymentDate, amount, method: PaymentMethod, reference, recordedByUserId }`. `PaymentMethod ∈ 'bankTransfer'|'creditCard'|'cash'|'cheque'|'directDebit'`.

`PolicyDocument { id, policyId, type: PolicyDocType, fileName, uploadedAt, uploadedByUserId }`. `PolicyDocType ∈ 'application'|'policy'|'receipt'|'medical'|'endorsement'|'cancellation'|'other'`.

`MotorDetails { vehicleBrand, vehicleModel, licenseNo, engineNo, chassisNo, registerYear, noPassenger: number, typeDriver, typeVehicle, notes }`.

`PropertyDetails { insuredName, insuredAddress, buildingCoverage, furnitureCoverage, stockCoverage, otherCoverage, otherDetail, notes }`.

`Rider { name, premium, notes }`, `Beneficiary { name, relation, share: number /* percent */ }`.

#### `stores/commissions.ts` — `defineStore('commissions', ...)`

State refs:
- `transactions: ref<CommissionTransaction[]>([])`
- `runs: ref<CommissionRun[]>([])`
- `mode: ref<CommissionMode>('asEarned')` — `'asEarned'|'advance'`
- `referralConfig: ref<ReferralBonusConfig>(...)`
- Computed: `stats`

**Interface `CommissionTransaction`:**

```
id
type: 'earning'|'override'|'clawback'|'referralBonus'
status: 'unsettled'|'settled'|'reversed'
agentId, policyId
policyEventId                          // idempotency anchor
idempotencyKey
reversesTxnId: string|null              // clawbacks point back at the original earning
basePremium: number
payerLevel: AgentLevel|null
diffPct: number                         // differential override %
amount: number
createdAt
settledByPayoutId: string|null
```

**Interface `CommissionRun`:** `id, policyId, policyEventId, policyEventType, runAt, transactionIds[]`.

**Interface `ReferralBonusConfig`:** `enabled, type: 'flat'|'pctOfFirstYear', flatAmount, pctValue`.

**Interface `ChainStep`** (computed preview, not persisted): `agent: Agent, role: 'writing'|'override'|'compressed', diffPct, amount`.

#### `stores/carrierContacts.ts` — `defineStore('carrierContacts', ...)`

State refs:
- `groups: ref<CarrierContactGroup[]>(seedGroups())`
- Computed: `byCarrier`

**Interface `CarrierContactGroup`:**

```
id
carrierCode: string                      // FK by code (e.g. 'AIA')
name
emails: string[]                         // multi-TO
department: ContactDepartment            // 'new_business'|'underwriting'|'policy_issue'|'claims'|'other'
insuranceTypes: InsuranceType[]
isDefault: boolean
notes?: string
active: boolean
```

`InsuranceType` union (15 values):
`'life' | 'group_life' | 'ci' | 'health' | 'group_health' | 'pa' | 'motor' | 'cmi' | 'fire' | 'marine' | 'travel' | 'liability' | 'pet' | 'other'`.

#### `stores/emailTemplates.ts` — `defineStore('emailTemplates', ...)`

State ref: `templates: ref<EmailTemplate[]>(seedTemplates())`.

**Interface `EmailTemplate`:** `id, label, desc, icon, department: ContactDepartment, subject, body, isBuiltIn: boolean`.
Supporting: `TemplateVariableSpec { name, label }`.

### 3.3 Page-local entities (no store yet)

Three modules carry their entity interface inside the page file, not the stores folder:

#### `pages/carriers/CarrierManagement.vue`

**Interface `Carrier`:**

```
id, code, name, nameEn
nicknameTh                              // Access INC_nickname
type: CarrierType                       // (union likely 'life'|'non-life'|'mixed' — defined nearby)
subType: CarrierSubType
compInsureCode                          // Access Comp_insure_code
oicInsureComCode                        // Access OIC_InsureCom_Code
oicLicense                              // our agency license
taxId, phone, email, website, address, logoUrl?: string|null
productCount, contractCount: number     // denormalized counts
since: string                           // BE year
active: boolean
```

#### `pages/products/ProductManagement.vue`

**Interface `Product`:**

```
id, code, name, nameEn
carrierId                               // FK Carrier
type: ProductType
summary
coverage: number
durationYears, payYears: number
premiumMode: PremiumMode
minPremium, maxPremium: number
minAge, maxAge: number
gender: Gender                          // 'all'|'male'|'female'
requireMedical, smokerAccepted, preexistingExcluded: boolean
occupationClasses: OccClass[]           // 'class1'..'class4'
notes
active: boolean
```

Local `CarrierRef { id, code, name }` is a hand-typed projection of the carrier store.

#### `pages/contracts/ContractManagement.vue`

**Interface `Contract`:**

```
id, contractNo, carrierId
effectiveFrom: string
effectiveTo: string | null
schedule: ScheduleRow[]                 // commission table per product
notes
active: boolean
```

`ScheduleRow { productId, firstYearRate: number, renewalRate: number }`.

#### `pages/settings/TenantSettings.vue`

Inline reactive shape (no exported interface) — represents the **tenant** (org) entity the new schema will need:

```
profile = {
  name, nameEn, taxId, oicLicense, phone, email, website,
  address, district, amphoe, province, postcode
}
commissionMode: 'asEarned' | 'advance'
payout = { cycle: 'weekly'|'biweekly'|'monthly', minBalance: number, autoApprove: boolean }
brandColor: string                       // hex
emailSignature: string
auditEntries: AuditEntry[]               // { id, time, user, action, target, ip, result: 'success'|'failed' }
```

---

## 4. Frontend route map

```
src/pages/
├── Dashboard.vue                  (/) module catalogue tiles
├── ModulePlaceholder.vue          (generic stub for unimplemented modules)
├── auth/
│   ├── AuthModule.vue             (auth shell)
│   ├── Login.vue                  (/auth/login)
│   ├── Register.vue               (/auth/register — tenant signup)
│   ├── ForgotPassword.vue
│   ├── ResetPassword.vue
│   ├── MfaSetup.vue
│   └── AcceptInvitation.vue
├── settings/
│   └── TenantSettings.vue         (/settings — profile, commission mode, payout cycle, branding, audit)
├── carriers/
│   └── CarrierManagement.vue      (/carriers — CRUD + per-carrier contact groups)
├── products/
│   └── ProductManagement.vue      (/products — CRUD)
├── contracts/
│   └── ContractManagement.vue     (/contracts — carrier appointments + commission schedule)
├── agents/
│   ├── AgentsSubnav.vue           (sub-nav)
│   ├── AgentList.vue              (/agents)
│   ├── AgentHierarchy.vue         (/agents/hierarchy — MLM tree visualisation)
│   ├── AgentTreeNode.vue          (recursive tree node helper)
│   └── AgentRecruitment.vue       (/agents/recruitment — recruitment links)
├── customers/
│   ├── CustomersSubnav.vue
│   ├── CustomerList.vue           (/customers)
│   └── CustomerReferral.vue       (/customers/referral — referral links)
├── policies/
│   └── PolicyList.vue             (/policies — quote → application → issued → active)
├── commissions/
│   └── CommissionEngine.vue       (/commissions/engine — ledger, runs, payouts share file)
└── support/
    ├── AgentSupport.vue           (/support — agent-facing case tracking)
    └── AgentOperationSupport.vue  (/ops — back-office ops dashboard)
```

Modules in `MODULES` array that have **no concrete page yet** (fall through to `ModulePlaceholder.vue`): `payouts` (`/payouts`), `reports` (`/reports`), `notifications` (`/notifications`), `platform-admin` (`/admin`), and the `commission-ledger` sibling route.

### Composables (`frontend/src/composables/`)

| File | One-line purpose |
|---|---|
| `useCaseStatus.ts` | Pure-function state machine for support cases — legal transitions, audit metadata, AI-summary → next-status mapping. Designed to port to PHP server-side. |
| `useDeepseekApi.ts` | Mocked DeepSeek chat-completions client (POST `/v1/chat/completions`); ready to swap to a real fetch. |
| `useEmailApi.ts` | Outbound mail client with two modes: `mock` (in-browser simulation with ~2% bounce rate) and a real-backend mode. Tracks queue → sending → sent → delivered. |
| `useQuotation.ts` | Quotation data model + AI extraction from carrier reply emails (calls DeepSeek) + PDF rendering. |
| `useQuotationPdf.ts` | Render a Quotation to PDF via jsPDF `.html()` (rasterizes HTML so Thai fonts work without embedding Sarabun). |

---

## 5. Cross-source field mapping table

Notation: `—` = absent in source; `*` = field is structurally there but stored differently (e.g. string-as-date vs date column).

### 5.1 Carrier / Insurance Company

| Domain field | Access (`Insure_company.xlsx`) | Strapi | Frontend (`Carrier`) |
|---|---|---|---|
| business code | `INC_Code` (varchar(3)) | — | `code` |
| name (Thai) | `INC_Name_TH` | — | `name` |
| name (English) | `INC_Name_En` | — | `nameEn` |
| nickname (Thai) | `INC_nickname` | — | `nicknameTh` |
| insurance type | `Company_Insure_Type` + `Sub_Insurer_type` + `Company_insuretypeID` | — | `type` + `subType` |
| status | `Status` (`Active` etc.) | — | `active` (boolean) |
| tax id | `Tax_ID` (varchar(13)) | — | `taxId` |
| address (WHT) | `Address_for_WH` | — | `address` |
| OIC code | `OIC_InsureCom_Code` (4 chars, e.g. `1003`) | — | `oicInsureComCode` |
| carrier internal code | `Comp_insure_code` | — | `compInsureCode` |
| bank account 1 | `bank_account_1` | — | — |
| our OIC license | — | — | `oicLicense` |
| phone | — | — | `phone` |
| email | — | — | `email` |
| website | — | — | `website` |
| logo | — | — | `logoUrl` |
| product count (denorm) | — | — | `productCount` |
| contract count (denorm) | — | — | `contractCount` |
| since | — | — | `since` (BE year string) |

### 5.2 Agent / Influencer

| Domain field | Access (`Agent_para`) | Strapi (`users-permissions_user` + components) | Frontend (`Agent`) |
|---|---|---|---|
| business code | `Insure_Agent_Code` | `agent_type`+`agent_year`+`agent_code` (composite numeric) | `agentCode` |
| user-id surrogate | — | `id` int | `id` string |
| type (agent vs influencer) | `TypeofInfuencer` (`AG`/`IN`) | `agent_type` (`ag`/`in`) | — (implied by tree position) |
| year of recruitment | (encoded in code) | `agent_year` | `joinedAt` |
| signup type | — | `signup_type` (`personal`) | — |
| login username | — | `username` | — |
| login email | `Email` | `email` | `email` |
| login password | — (separate `PW_InsHub` table) | `password` (bcrypt) | — |
| first name (Thai) | `Agent_Name_Thai` | `agent_info.firstname` | `firstName` |
| last name (Thai) | `Agent_Surname_Thai` | `agent_info.lastname` | `lastName` |
| first name (Eng) | `Agent_Name_Eng` | — | `firstNameEn` |
| last name (Eng) | `Agent_Surname_Eng` | — | `lastNameEn` |
| nickname | — | — | `nickname` |
| gender | `Gender` (`F`/`M`) | — | `gender` |
| national ID | `Agent_Tax_ID` (?) | `agent_info.citizen_id` (bigint) | `idCard` |
| tax ID | `Agent_Tax_ID` | `agent_info.tax_id` (bigint) | `taxId` |
| corporate name | — | `agent_info.juristic_name` | `juristicName` |
| branch | — | `agent_info.branch` | — |
| company register date | — | `agent_info.company_register_date` | — |
| VAT type | `VAT_TYPE` (varchar(1)) | — | `vatType` |
| birth date | `Birth_date` | `agent_info.birthdate` | `birthDate` |
| mobile | `Mobile_phone` | `agent_info.phone_number` | `phone` |
| landline | `Tel_Phone` | — | — |
| Line ID | `Line_ID` | `agent_info.line_id` | `lineId` |
| Facebook name | `Facebook_Name` | — | — |
| email (secondary) | `Email2` | — | — |
| **invoice/billing address** | `Address_no`, `Building_Floor`, `Moo`, `Moo_ban`, `Soi`, `Road`, `Kwang`, `Khet`, `Province`, `Zip_Code` (10 columns) | `agent_invoice_address.{address,province,district,sub_district,postcode}` (5) | `address`+`subDistrict`+`district`+`province`+`postcode` (5, free-text street) |
| **doc/mailing address** | — (single address only) | `agent_document_address.{...,same_as_invoice}` (6) | — (single address) |
| license — life | `LicenseLife_No`, `Exp_Life` | `agent_license_life` (`license_id`, `license_expiration_date`, `have_license`) | `licenseLifeNo`, `licenseLifeExpiry` |
| license — non-life | `LicenseNon_Life_No`, `Exp_Non_Life` | `agent_license` (same component table, separate row) | `licenseNonLifeNo`, `licenseNonLifeExpiry` |
| license — legacy single | — | — | `licenseNumber`, `licenseIssuer`, `licenseExpiry` |
| bank — name | `Bank_name` (FK→BankName_Para by-name) | `agent_bank_account.issuing_bank` | `bank.bankName` |
| bank — account no | `Bank_Account` | `agent_bank_account.account_number` | `bank.accountNo` |
| bank — account name | `Bank_Account_name` | — | `bank.accountName` |
| MLM upline | `MLM_Upline` (FK self by-code) | `upline` (int self-FK) | `parentAgentId` |
| MLM upline 2 | `MLM_Upline2` | — | — |
| team / hierarchy label | `Team`, `Team LV1`, `Team LV2`, `Team_No` | — | `level` (`l1..l5`) |
| commission % | (per-product in `Main_Product`) | — | `commissionPct` (single overall %) |
| join date | `Date_Apply` | (encoded) | `joinedAt` |
| head start date | `Head Start Date` (varchar dd/m/yyyy) | — | — |
| grace period end | `Grace period End` (varchar dd/m/yyyy) | — | — |
| source | `Source` (`TEST`, `In-House`) | — | — |
| status | `Head_Status` (`Active`) | `confirmed`+`blocked` | `active` |
| doc completion status | (via `App_Doc_Control` per app) | `agent_doc_status` boolean + `agent_doc_description` text + `components_agent_agent_doc_statuses` | — |
| dashboard URLs | — | `components_agent_agent_dashboards.{income_url, job_url, expire_reminder_url}` | — |
| recruitment link | — | — | `RecruitmentLink { token, clicks, signups, ... }` |
| notes | — | — | `notes` |

Mismatches:
- Access uses one structured address (10 atomic fields); Strapi uses two addresses (invoice + document); Frontend collapses back to one address with a `mailing` sub-block on `Customer` (but NOT on `Agent`).
- Frontend `commissionPct` is a single number on the agent — but Access stores commission per product-per-year (`AgCommission_1..11`) and the contracts page (`Contract.schedule`) stores rate per product. The frontend agent-level `commissionPct` is a simplification that doesn't match either source's rate structure.
- `agent_code` is a **numeric column in Strapi** but a **string `AG200000`/`IN210239` in Access** — they're not directly comparable; the prefix lives in `agent_type`.

### 5.3 Customer / Client

| Domain field | Access (`Client`) | Strapi | Frontend (`Customer`) |
|---|---|---|---|
| business code | `Client_Code` (varchar(8)) | — | `customerCode` |
| customer type | `Type_Cust` (`1`/`2`) | — | `customerType` (`individual`/`corporate`) |
| title Thai | `Name_Title_Thai` | — | `titleTh` |
| title English | `Name_Title_Eng` | — | `titleEn` |
| first name Thai | `Name_Thai` | — | `firstName` |
| last name Thai | `Surname_Thai` | — | `lastName` |
| first name English | `Name_Eng` | — | `firstNameEn` |
| last name English | `Surname_Eng` | — | `lastNameEn` |
| nickname | — | — | `nickname` |
| juristic name | — | — | `juristicName` |
| tax ID | — | — | `taxId` |
| National ID | `National_ID` | — | `idCard` |
| National ID expiry | `National_ID_ExpD` | — | `nationalIdExpiry` |
| passport | `Passport` | — | `passport` |
| race | `Race` | — | — |
| nationality | `Nationality` (FK→nation by-name) | — | `nationality` |
| religion | `Religion` (FK→religion by-name) | — | `religion` |
| gender | `Gender` (Thai text) | — | `gender` (`male`/`female`/`other`) |
| marital status | — | — | `maritalStatus` |
| birth date | `Birth_date` | — | `birthDate` |
| occupation | `Occupation` (FK→Occupation by-name) | — | `occupation` |
| position held | `Position_held` | — | `position` |
| employer | `Occupation_Company` | — | `employerName` |
| annual income | `Cus_Annually_Income` | — | `monthlyIncome` (note: annual vs monthly mismatch) |
| mobile | `Mobile_phone` | — | `phone` |
| landline | `Tel_Phone` | — | — |
| Line ID | `Line_ID` | — | `lineId` |
| Email | `Email1` | — | `email` |
| Email 2 | `Email2` | — | — |
| Facebook | `Facebook_Name` | — | — |
| permanent address | 10 fields `Per_*` | — | 5 fields: `address`, `district` (sub-district), `amphoe` (district), `province`, `postcode` |
| mailing address | 10 fields `Mailing_*` | — | `mailing: MailingAddress` (5) + `mailingSameAsRegistered: bool` |
| corporate contact | `Contact_person_receive`, `Contact_person_receive_address`, `Contact_person`, `Contact_Mobile_phone`, `Contact_Email` | — | `contactPerson: { name, phone, email, position }` |
| assigned agent | (implicit via `Application.Insure_Influencer_Code`) | — | `assignedAgentId` |
| created-by agent | — | — | `createdByAgentId` |
| registered at | — | — | `registeredAt` |
| last contact | — | — | `lastContact` |
| KYC docs | (in `App_Doc_Control` per application) | — | `kycDocs: KycDoc[]` |
| assignment history | — | — | `assignmentHistory[]` |
| denorm counts | — | — | `activePolicyCount`, `totalPolicyCount` |

Mismatches:
- Access has 4 customer name fields (Thai title + name + surname + Eng equivalents). Frontend has 6 (adds nickname). Both lack juristic name + tax ID on the customer in Access — corporate customers carry juristic data on the policy (`Insured_CompName`).
- Income: Access stores annual (`Cus_Annually_Income`), frontend stores monthly (`monthlyIncome`). Migration needs a /12.
- Religion / nationality: Access stores Thai text values; corresponding `nation.xlsx` / `religion.xlsx` lookups exist but the join is by-name not by-id.

### 5.4 Product

| Domain field | Access (`Main_Product`) | Strapi | Frontend (`Product`) |
|---|---|---|---|
| business code | `Product_Code` (varchar(9)) | — | `code` |
| name | `Product_Name` | — | `name` |
| name EN | — | — | `nameEn` |
| carrier | `INC_Code` | — | `carrierId` |
| type / categories | `Insure_Type` + `Categories` + `Sub_Categories` + `Sub_Categories2` + `Main_Rider` | — | `type: ProductType` |
| coverage default | — | — | `coverage` |
| duration years | — | — | `durationYears` |
| pay years | — | — | `payYears` |
| premium mode | — | — | `premiumMode` |
| min/max premium | — | — | `minPremium`, `maxPremium` |
| min/max age | `minAge`, `maxAge` | — | `minAge`, `maxAge` |
| min/max sum assured | `minSumAssure`, `maxSumAssure` | — | (covered by `coverage`?) |
| min/max RAR | `maxRAR`, `minRAR` | — | — |
| gender restriction | — | — | `gender` (`all`/`male`/`female`) |
| medical underwriting | — | — | `requireMedical` |
| smoker accepted | — | — | `smokerAccepted` |
| preexisting excluded | — | — | `preexistingExcluded` |
| occupation classes | — | — | `occupationClasses[]` |
| company commission % yr 1..11+ | `ComCommission`, `ComCommission_2..10`, `ComCommission_11Up` | — | (not on Product — moved to Contract.schedule, but only year 1 + renewal) |
| agent commission % yr 1..11+ | `AgCommission`, `AgCommission_2..10`, `AgCommission_11Up` | — | (same) |
| influencer commission % yr 1..11+ | `InCommission`, `InCommission_2..10`, `InCommission_11Up` | — | — |
| commission code (versioned) | `Commission_Code`, `Valid_start`, `Valid_End` | — | (Contract has `effectiveFrom/To`) |
| active | (implied via `Valid_End` sentinel) | — | `active` |
| notes | — | — | `notes` |

Mismatches:
- Access stores **33 commission % cells per product per channel** (3 channels × 11 years). Frontend collapses to 2 numbers per product per contract (`firstYearRate`, `renewalRate`). Big information loss in migration unless the new schema models per-year rates explicitly.
- Access ties commission rates directly to product rows (versioned by `Valid_start`/`Valid_End`). Frontend ties them to a separate `Contract` table. These are alternative schemas, not equivalent.

### 5.5 Policy / Application

`Application.xlsx` is the system-of-record. The frontend `Policy` is the equivalent. Strapi has nothing.

| Domain field | Access (`Application`) | Frontend (`Policy`) |
|---|---|---|
| quote no | (Access does not separate quote / app — one row) | `quoteNo` |
| application no | `Application_code` (PK) | `applicationNo` |
| policy no | `Policy_Number` | `policyNo` |
| notion no (internal) | `Notion_No` | — |
| customer FK | `Client_Code` | `customerId` |
| writing agent | `Insure_Influencer_Code` | `writingAgentId` |
| internal note (handle) | `Internal_Note` | (in `notes`) |
| product FK (main) | `Product_Code` + `Product_Name` | `productId` |
| carrier FK | `INC_Code` | `carrierId` |
| riders | `Rider1..Rider5` (5 FK slots) + `Rider1_Premium..` + `Rider1_Com_*` | `riders: Rider[]` (open-ended array) |
| ref previous app | `Ref_app_to` | — |
| create date | `Create_date` | (in `events[type=created].at`) |
| application date | `App_date` | `quoteDate` |
| coverage start | `Coverage_start` | `effectiveDate` |
| coverage end | `Coverage_End` | `expiryDate` |
| period paid end | `Period_Paid_End` | — |
| policy end | `Policy_End` | (duplicates `expiryDate`?) |
| issue date | (Access has no explicit issue date — implied by `Payment_Date` or `Mailing_Date`) | `issueDate` |
| next premium due | `FirstDue_instDate` / `NextDue_inst` | `nextPremiumDue` |
| cancel date | (`Cancel_Status` only) | `cancelDate` |
| lapse date | — | `lapseDate` |
| policy year | `Policy_Year` (varchar) | `policyYear` (number) |
| act year | `Act_Year` (int) | `actYear` |
| new or renew | `NewOrRenew` (`1`/`2`?) | `newOrRenew` (`new`/`renew`) |
| coverage amount | `Coverage_amt` | `coverage` |
| annual premium | `Premium` (post-VAT?) / `Main_Premium` | `annualPremium` |
| duty stamp | `Duty_stamp` | — |
| VAT | `Vat` | — |
| total premium paid | `Total_Premium_Paid` | — |
| net customer paid | `Net_Cus_paid` | — |
| WHT status / amount | `WHT_Status`, `WHT_Amt` | — |
| premium mode | `Type_of_paid` + `Note_Type_of_paid` + `Installment_Term` | `premiumMode` |
| finance company | `Finance_Company` | — |
| installment due 1st | `FirstDue_inst`, `FirstDue_instDate` (varchar!) | — |
| installment due last | `LastDue_instDate` (varchar!) | — |
| payment method | `Payment_method` (FK→para) | (in `payments[].method`) |
| subsidies | `Subsidise_from_AG`, `Front_End_Fee`, `Discount_Amount`, `Subsidise_to_Finance` | — |
| credit-card fee | `CreditCard_Fee` | — |
| beneficiaries (up to 4) | `Beneficiary`, `Beneficiary_Relation` (`2/3/4`) | `beneficiaries: Beneficiary[]` (open array w/ `share %`) |
| motor — type driver | `Type_Driver` | `motor.typeDriver` |
| motor — type vehicle | `Type_Vehicle` | `motor.typeVehicle` |
| motor — brand | `Vehicle_Brand` | `motor.vehicleBrand` |
| motor — model | `Vehicle_model` | `motor.vehicleModel` |
| motor — license plate | `License_no` | `motor.licenseNo` |
| motor — engine no | `Engine_No` | `motor.engineNo` |
| motor — chassis no | `chassis_No` | `motor.chassisNo` |
| motor — register year | `Register_Year` | `motor.registerYear` |
| motor — no passengers | `No_Passenger` (str) | `motor.noPassenger` (number) |
| motor — notes | `NoteCarAsset` | `motor.notes` |
| fire/property — name | `Insured_CompName` | `property.insuredName` |
| fire/property — address | `Insured_Address` | `property.insuredAddress` |
| fire/property — building cov | `Insured_Aset_Buil_Cov` | `property.buildingCoverage` |
| fire/property — furniture cov | `Insured_Aset_Fur_Cov` | `property.furnitureCoverage` |
| fire/property — stock cov | `Insured_Aset_Stok_Cov` | `property.stockCoverage` |
| fire/property — other cov | `Insured_Aset_Other_Cov` | `property.otherCoverage` |
| fire/property — other detail | `Insured_Aset_Other_Detl` | `property.otherDetail` |
| fire/property — note | `Insured_Aset_Note` | (in `property.notes`) |
| fire/property — phone | `Insured_Mobile_phone` | — |
| status | `Policy_Status` (FK→Policies_status_para) | `status: PolicyStatus` |
| status note | `Policy_Status_Note` | (in `notes`) |
| freelook | `Freelook_Status` (bool) | `freelookActive` |
| mailing address by policy | `Mailing_Add_by_Policy` | — |
| mailing date | `Mailing_Date` (varchar!) | — |
| mailing note | `Mailing_Note` | — |
| recording user | `U` (e.g. `Sumontha`) | (in `events[].byUserId`) |
| commission record check | `ComRec_Check` (`Pending`/`Complete`) | — |
| events / history | (no event table — derive from date columns) | `events: PolicyEvent[]` |
| payments | (single payment per app row only) | `payments: PolicyPayment[]` |
| documents | (`App_Doc_Control` paths per app) | `documents: PolicyDocument[]` |

### 5.6 Commission

| Domain field | Access (`Application` columns) | Frontend (`CommissionTransaction`) |
|---|---|---|
| (rate)main % InH (carrier→us) | `Main_Com_InH` | (derived from Contract.schedule) |
| (rate)main % AG (us→agent) | `Main_Com_AG` | (derived) |
| rider rates 1..5 InH | `Rider1..5_Com_InH` | (derived) |
| rider rates 1..5 AG | `Rider1..5_Com_AG` | (derived) |
| amount main InH | `Main_ComAmt_InH` | (amount on `type='earning'`) |
| amount main AG | `Main_ComAmt_AG` | (amount on `type='earning'`) |
| amount rider InH | `Rider1..5_ComAmt_InH` | — |
| amount rider AG | `Rider1..5_ComAmt_AG` | — |
| settlement: InH rebate status | `Rebate_Status` | `status: 'unsettled'/'settled'` |
| settlement: InH rebate earn date | `Rebate_Earn_Date` | `createdAt` |
| settlement: override status | `OV_status` | — |
| settlement: override earn date | `rebate_OV_date` | — |
| calc InH amount | `Cal_Rebate_Amt` | — |
| calc override amount | `Cal_Rebate_OV` | — |
| actual InH amount | `Act_Rebate_Amt` | `amount` |
| actual override amount | `Act_Rebate_OV` | `amount` (separate row, `type='override'`) |
| validate InH | `Validate_Rebate_Amt` | — |
| validate override | `Validate_Rebate_OV` | — |
| AG rebate status | `Rebate_Status_AG` | `status` |
| AG rebate rec date | `Rebate_Rec_Date_AG` | `settledByPayoutId` (indirect) |
| AG calc amount | `Cal_Rebate_Amt_AG` | — |
| AG actual amount | `Act_Rebate_Amt_AG` | `amount` |
| AG check | `Check_AG_Rebate` | — |
| clawback / refund | `Refund_Premium`, `Refund_Vat`, `Refund_Total_Premium`, `Refund_Discount`, `Net_Refund_Amount`, `Refund_Rebate_Amt`, `Refund_Rebate_OV` | `type='clawback'`, `reversesTxnId` |
| txn type | (implicit — column choice) | `type: 'earning'/'override'/'clawback'/'referralBonus'` |
| idempotency anchor | (implicit — one row per application + per rider) | `policyEventId`, `idempotencyKey` |
| payer level | (implicit via MLM tree) | `payerLevel: AgentLevel` |
| differential % | (computed from MLM chain) | `diffPct` |

Mismatches:
- Access is **wide / one-row-per-policy** with parallel columns for main/rider 1..5, carrier/agent channel, calc/actual, validate flags. Frontend is **tall / event-sourced** with one txn row per (policy_event × payer_agent).
- Access does not model commission *runs* (idempotency by policy_event_id) — the frontend does (`CommissionRun.transactionIds`).

### 5.7 Tenant / Org-level

| Domain field | Access | Strapi | Frontend (`TenantSettings`) |
|---|---|---|---|
| org name TH | — | — | `profile.name` |
| org name EN | — | — | `profile.nameEn` |
| tax ID | — | — | `profile.taxId` |
| OIC license | — | — | `profile.oicLicense` |
| phone / email / website / address | — | — | `profile.{phone,email,website,address,district,amphoe,province,postcode}` |
| brand color | — | — | `brandColor` |
| email signature | — | — | `emailSignature` |
| commission mode (asEarned vs advance) | — | — | `commissionMode` |
| payout cycle | — | — | `payout.{cycle,minBalance,autoApprove}` |
| audit log | — | (no business audit table — only `strapi_webhooks`, no DB audit) | `auditEntries[]` |

The tenant entity is **brand-new** in the frontend — no equivalent in Access (which was a single-org desktop app) and no equivalent in Strapi (single-instance).

### 5.8 Lookup / parameter tables (Thai locale)

| Frontend concept | Access table | Strapi |
|---|---|---|
| Bank list | `BankName_Para` (11) | hardcoded in `components_agent_agent_bank_accounts.issuing_bank` strings |
| Thai location (province/amphoe/tambon/zip) | `Location` (7,460 rows) | flat strings on each address component row |
| Country / nationality | `nation` (242) | — |
| Religion | `religion` (11) | — |
| Occupation | `Occupation` (48) | — |
| Title (Mr/Mrs/บริษัท…) | `prefix_para` (244, composite key) | — |
| Insurance company | `Insure_company` (45) | — |
| Insurance product | `Main_Product` (894) | — |
| Vehicle catalog (Redbook) | `Motor_para` (32,664) | — |
| Vehicle market group | `MotorMarketGrouppara` (10) | — |
| Payment method | `Payment_method_para` (3) | — |
| Payment-to-carrier status | `Payment_InsComp_Status_para` (3) | — |
| Payment-to-carrier "to" | `Payment_InsComp_to_para` (2) | — |
| Policy status | `Policies_status_para` (11) | — |
| Paid status | `paidstatus_para` (16) | — |

---

## 6. Open questions

1. **`App_Doc_Control` semantics.** 3,853 rows keyed on `Application_code`, with 28 path columns (slot per document type: ID, IDkid, Passport, CarReg, PolRE, Extpol, slip, oth1..6, all duplicated as `O_path_*` and `E_path_*`). Is this a **per-application document index** (one row per app, multiple doc kinds per row) or a **history table** (multiple rows per app, each pinning a doc version)? Sample suggests one-row-per-app. The duplicate `O_path_*` (original network share) and `E_path_*` (Windows-local "Desktop" path) need clarification — are both authoritative, or is one a backup? Are the file paths still valid storage locations to migrate from?

2. **`PW_InsHub` — eight internal users with plaintext passwords.** Is this the canonical staff login table for the legacy Access UI, completely separate from `Agent_para` (agents/influencers) and from Strapi's `users-permissions_user` (which also has agent-shaped business columns)? Several agents would have all three identities — how are they reconciled? Are the `1482-02-19` `DateCreate` values legacy bad data?

3. **`agent_dashboards` vs `components_agent_agent_dashboards` in Strapi.** The plural-named singleton (1 row, with components for `additional_infos`, `manuals`, `staff_contact_infos`) appears to be a **shared global dashboard config**, while `components_agent_agent_dashboards` (632 rows of `{income_url, job_url, expire_reminder_url}`) is **per-agent**. Confirm.

4. **`Application` row = policy or application?** A single Access row carries `Application_code`, `Notion_No`, AND `Policy_Number`. The frontend models `quoteNo` / `applicationNo` / `policyNo` as distinct id fields on the same `Policy` entity (only one filled per lifecycle stage). Is the Access intent identical (three IDs, one row, populated as the lifecycle advances) or do separate row-versions exist for the quote stage that aren't in the Excel export? Worth confirming against the original `.accdb`.

5. **Address granularity.** Access uses up to 10 columns per address (`Address_no`, `Building_Floor`, `Moo`, `Moo_ban`, `Soi`, `Road`, `Kwang`, `Khet`, `Province`, `Zip_Code`). Strapi collapses to 5 (`address` free-text + 4 admin levels). Frontend uses 5 on Customer (`address` + 4 admin) plus a `mailing` sub-block, and uses 5 on Agent. Which granularity is the target for the new schema?

6. **Religion / nationality FK strategy.** Access stores Thai *names* (`พุทธ`, `ไทย`) in `Client.Religion`, `Client.Nationality`, while `religion.xlsx` and `nation.xlsx` exist as lookup tables with surrogate IDs. Is the legacy data linked by-name (i.e. the FK column holds the description, not the ID)? If so, migration needs a join-by-Thai-string step. Confirm before designing FK constraints.

7. **Commission rates structure.** Access pins per-year (`AgCommission`, `AgCommission_2..10`, `AgCommission_11Up`) commission % directly on the product, with a versioning column pair (`Valid_start`/`Valid_End`). The frontend pins them on the `Contract` (only year-1 + renewal). The frontend also has a single `commissionPct` on `Agent` (unused?). Which model is canonical for the new schema — versioned per-year rates on Product, or simpler 2-rate-per-product rates on Contract?

8. **`Rider1..5` vs `riders: Rider[]`.** Access has exactly 5 fixed rider slots with parallel premium and commission columns. Frontend has an open-ended array `riders: Rider[]`. Are there policies in the legacy data with >5 riders that were truncated, or is 5 truly the cap?

9. **`Beneficiary` / `Beneficiary2..4` — 4-slot vs open array.** Same question: 4 fixed slots in Access, open array in frontend. The frontend also adds a `share: number` (percentage) which has no counterpart in Access (`Beneficiary_Relation` is the only attribute) — does the legacy data have implicit share allocation rules?

10. **Date storage inconsistencies.** Inside `Application.xlsx` some date-shaped columns are real `datetime` (`Coverage_start`, `Payment_Date`), while others are **strings** (`FirstDue_instDate`='18/12/2025', `LastDue_instDate`='18/5/2026', `Mailing_Date`='1/1/9999', `Head Start Date`, `Grace period End`). Are the string dates legacy text-field garbage, or do they encode something the datetime columns don't (e.g. uncertainty / placeholder year `9999`)?

11. **`MLM_Upline2`.** Most rows have `'0'`. Was this column used historically (a second upline for some sponsorship model) or always inert? The frontend models a single `parentAgentId` only.

12. **`Team`, `Team LV1`, `Team LV2`, `Team_No` — four team columns on Agent.** No matching `Team` lookup table in the export. What's the difference between `Team` (`Team1`), `Team_No` (`Team5`), `Team LV1` (`Team1-1`), and `Team LV2` (all-empty)? Are these tree-position labels independent of `MLM_Upline`, or a denormalized projection?

13. **Carrier contact groups** are in the frontend (`stores/carrierContacts.ts`, seeded `CarrierContactGroup[]`) but have **no representation in Access or Strapi**. Confirm this is a green-field addition.

14. **Email templates** likewise are frontend-only (`stores/emailTemplates.ts`, `EmailTemplate` with `isBuiltIn: boolean`). Green-field?

15. **Tenant / multi-tenancy.** The new schema is presumably multi-tenant (the frontend has tenant-settings, audit log, `signup_type` on Strapi user…), but neither Access nor Strapi enforces a `tenant_id` on existing rows. Is the legacy data all one tenant?

16. **Strapi `users-permissions_user.agent_code` is `bigint` but Access `Insure_Agent_Code` is `varchar(8)` with letter prefix.** Pinning the new schema to bigint loses the prefix; pinning to varchar requires reformatting the Strapi numeric IDs. Which direction?

17. **Audit log structure.** The frontend's `AuditEntry { time, user, action, target, ip, result }` is hand-crafted seed data. Neither legacy source has a real audit log table (`strapi_webhooks` ≠ audit). Will the new schema add one from scratch?

18. **The Access screenshot doc references** "เพิ่มสลักหลังกรมธรรม์" (add policy endorsement) and "ค้นหาข้อมูลกรมธรรม์-ประกันกลุ่ม" (search group policies). The exports include neither an `Endorsement` table nor a `GroupPolicy` table. Do they exist in the original `.accdb` but were excluded from the Excel dump, or is the UI flow unimplemented?

19. **`InsuranceType` union in frontend has 15 values** including `cmi` (CMI/พ.ร.บ.), `pet`, `liability`. Access encodes type via three columns on Product (`Insure_Type`, `Categories`, `Sub_Categories`, `Sub_Categories2`). Does each frontend value map cleanly to a (Categories, Sub_Categories) pair, or is human curation needed?

20. **Excel "version" duplication.** Files like `Client.xlsx` / `Client(1).xlsx` / `Client(2).xlsx` have identical byte sizes — assumed to be the same data exported repeatedly. Worth a content-hash diff to confirm none of the "(n)" suffixes carries newer rows.

21. **Currency / units.** The frontend comment says "stored in baht — Laravel will store integer satang; we'll mirror that later." Access stores baht as float (`Main_Premium=31753.32`). Has a decision been made on integer-satang vs decimal-baht for the new schema?
