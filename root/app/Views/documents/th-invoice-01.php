<?php
/**
 * Thai invoice (ใบแจ้งหนี้), version 1: INVOICE_TH_VERSION in Config\Constants while it is the one in use.
 * Rendered by App\Libraries\DocumentRenderer, which works out every value; this only prints them, escaped.
 * Never change a version once documents use it: copy it to the next number and change that instead.
 *
 * Frozen when the invoice is made and rendered once: it shows nothing that changes later, such as whether it is paid.
 *
 * @var array $doc DocumentRenderer::document(), with version and qr (a data URI, when there is something to pay)
 */
$e = static fn (?string $text): string => esc((string) $text);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>ใบแจ้งหนี้ <?= $e($doc['invoice_no']) ?> | OtterNova</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Sarabun:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent-color: #c97232;
            --muted-color: #f9e2c2;
        }
        body {
            border: 1px solid #eee;
            font-family: "Sarabun", serif;
            font-size: 12px;
            margin: 10px auto;
            width: 762px !important;
            height: 1123px !important;
            padding: 15px;
            display: flex;
            flex-direction: column;
        }
        .divider {
            height: 0;
            border-bottom: 2px var(--accent-color) solid;
            margin: 10px 0;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: "Prompt", sans-serif;
            margin: 0;
        }
        h1 {font-weight:400;}
        h1, h2, h3, .text-accent {color: var(--accent-color);}
        .text-grey {color: #666;}
        .text-center {text-align: center;}
        .text-right {text-align: right;}
        .row {display: flex;}
        .col {padding: 0 10px;display: flex;flex-direction: column;}
        .w-100 {width: 100% !important;}
        .w-50 {width: 50% !important;}
        .box {
            border: 1px #aaa solid;
            border-radius: 10px;
            padding: 10px;
        }
        .flex-grow {
            flex-grow: 1;
        }
        .amount-in-words {
            background-color: var(--muted-color);
            border-radius: 10px;
            padding: 10px;
        }
        .m-top {margin-top: 15px;}
        .m-bottom {margin-bottom: 15px;}
        table {
            border-collapse: collapse;
        }
        .main-table {
            table-layout: fixed;
            width: 100%;
        }
        .main-table th, .main-table td {
            padding: 5px;
            white-space: nowrap;
            word-break: break-all;
            overflow-wrap: break-word;
        }
        th {
            background-color: var(--muted-color);
            border-bottom: 2px var(--accent-color) solid;
        }
        .main-table tbody td {
            border-bottom: 1px var(--accent-color) solid;
        }
        .main-table tbody tr:last-child td {
            border-bottom: 2px var(--accent-color) solid;
        }
        td.total {
            border-top: 1px var(--accent-color) solid;
            font-size: 14px;
            font-weight: 700;
        }
        .status {
            border-radius: 10px;
            background-color: var(--muted-color);
            color: var(--accent-color);
            font-size: 14px;
            font-weight: 700;
            padding: 10px;
        }
        .footer {margin-top: auto;}
        .qr {width: 100px;}
        @page {size: A4; margin: 0;}
        @media print {
            body {border: 0; margin: 0 auto;}
        }
    </style>
</head>
<body>
<!-- HEADER (SHARED) -->
<div class="row">
    <div class="col">
        <img src="data:image/webp;base64,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" alt="OtterNova" style="width:40px">
    </div>
    <div class="col">
        <h1>OtterNova &middot; ออทเทอร์โนวา</h1>
    </div>
</div>
<div class="divider"></div>
<!-- BODY -->
<div class="text-center m-top m-bottom">
    <h1 class="m-bottom">ใบแจ้งหนี้ &middot; Invoice</h1>
</div>
<div class="row">
    <div class="col w-50">
        <div class="box flex-grow">
            <h3>ลูกค้า / Bill to</h3>
            <b><?= $e($doc['customer']['name']) ?></b><br/>
            <span class="text-grey">
                <?php if ($doc['customer']['trade'] !== null): ?><?= $e($doc['customer']['trade']) ?><br/><?php endif ?>
                <?php if ($doc['customer']['attention'] !== null): ?>เรียน / Attn: <?= $e($doc['customer']['attention']) ?><br/><?php endif ?>
                <?php foreach ($doc['customer']['address'] as $line): ?><?= $e($line) ?><br/><?php endforeach ?>
                <?php if ($doc['customer']['tax_id'] !== null): ?>
                เลขประจำตัวผู้เสียภาษี / Tax ID: <?= $e($doc['customer']['tax_id']) ?><?php if ($doc['customer']['branch'] !== null): ?> (<?= $e($doc['customer']['branch']) ?>)<?php endif ?>
                <?php endif ?>
            </span>
        </div>
    </div>
    <div class="col w-50">
        <div class="box flex-grow">
            <table class="w-100">
                <tr>
                    <td class="text-grey">เลขที่ / No.</td>
                    <td class="text-right"><b><?= $e($doc['invoice_no']) ?></b></td>
                </tr>
                <tr>
                    <td class="text-grey">วันที่ / Date</td>
                    <td class="text-right"><?= $e($doc['issued_on']) ?></td>
                </tr>
                <tr>
                    <td class="text-grey">ครบกำหนด / Due Date</td>
                    <td class="text-right"><?= $e($doc['due_on']) ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>
<table class="main-table w-100 m-top">
    <thead>
    <tr>
        <th class="text-center" style="width:55px !important;">ลำดับ<br/>No.</th>
        <th class="text-center" style="width:317px !important;">รายการ<br/>Description</th>
        <th class="text-center" style="width:70px !important;">จำนวน<br/>Qty</th>
        <th class="text-right" style="width:135px !important;">ราคาต่อหน่วย<br/>Unit Price</th>
        <th class="text-right" style="width:135px !important;">จำนวนเงิน<br/>Amount</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td class="text-center">1</td>
        <td style="white-space: normal; word-break: normal;">
            <b><?= $e($doc['line']['title']) ?></b><br />
            <span class="text-grey">
                <?php foreach ($doc['line']['details'] as $detail): ?><?= $e($detail) ?><br/><?php endforeach ?>
            </span>
        </td>
        <td class="text-center"><b>1</b></td>
        <td class="text-right"><?= $e($doc['line']['amount']) ?></td>
        <td class="text-right"><?= $e($doc['line']['amount']) ?></td>
    </tr>
    </tbody>
    <tfoot>
    <tr>
        <td colspan="2" rowspan="<?= $doc['withheld'] ? 5 : 3 ?>" style="vertical-align: top; white-space: normal; word-break: normal;">
            <?php if ($doc['total_words'] !== null): ?>
            จำนวนเงินเป็นตัวอักษร / Amount in words
            <div class="amount-in-words m-top">
                <b><?= $e($doc['total_words']) ?></b><br/>
                <?= $e($doc['total_words_en']) ?>
            </div>
            <?php endif ?>
        </td>
        <td colspan="2">รวมเป็นเงิน / Subtotal</td>
        <td class="text-right"><?= $e($doc['list_price']) ?></td>
    </tr>
    <tr>
        <td colspan="2">ส่วนลด / Discount<?php if ($doc['discount_code'] !== null): ?> <small class="text-grey">(<?= $e($doc['discount_code']) ?>)</small><?php endif ?></td>
        <td class="text-right"><?= $e($doc['discount']) ?></td>
    </tr>
    <tr>
        <td colspan="2" class="total">จำนวนเงินรวม / Total (<?= $e($doc['currency_code']) ?>)</td>
        <td class="text-right total text-accent"><b><?= $e($doc['total']) ?></b></td>
    </tr>
    <?php if ($doc['withheld']): ?>
    <tr>
        <td colspan="2">
            หักภาษี ณ ที่จ่าย / Withholding tax (<?= $e($doc['wht_rate']) ?>%)<br/>
            <small class="text-grey">สำหรับลูกค้านิติบุคคล / for business clients</small>
        </td>
        <td class="text-right">(<?= $e($doc['wht_amount']) ?>)</td>
    </tr>
    <tr>
        <td colspan="2"><b>ยอดชำระสุทธิ / Net payable</b></td>
        <td class="text-right text-accent"><b><?= $e($doc['payable']) ?></b></td>
    </tr>
    <?php endif ?>
    </tfoot>
</table>
<?php if ($doc['qr_request'] !== null): ?>
<div class="m-top box">
    <table class="w-100">
        <tr>
            <td>
                <h3>วิธีชำระเงิน / How to pay</h3>
                <?php if ($doc['qr'] !== null): ?><p>พร้อมเพย์ / PromptPay: สแกน QR / scan the QR code</p><?php endif ?>
                <p>โอนเงิน / Bank transfer: <?= $e($doc['bank']['name'] ?? '[ธนาคาร / Bank]') ?> · <?= $e($doc['bank']['account_name'] ?? '[ชื่อบัญชี / Account name]') ?> · <?= $e($doc['bank']['account_number'] ?? '[เลขที่บัญชี / Account no.]') ?></p>
                <?php if ($doc['withheld']): ?>
                <p class="text-grey">
                    หากหักภาษี ณ ที่จ่าย โปรดอัปโหลดหนังสือรับรอง (50 ทวิ) มาเข้าสู่ระบบ<br/>
                    If you withhold tax, please upload the 50 Tawi certificate to the system.
                </p>
                <?php endif ?>
            </td>
            <?php if ($doc['qr'] !== null): ?>
            <td style="width:105px; padding: 0 0 5px 5px">
                <img src="<?= $e($doc['qr']) ?>" alt="PromptPay QR" class="qr" />
            </td>
            <?php endif ?>
        </tr>
    </table>
</div>
<?php endif ?>
<div class="m-top box">
    <b>ออทเทอร์โนวาไม่ได้จดทะเบียนภาษีมูลค่าเพิ่ม</b> — ราคานี้ไม่มีภาษีมูลค่าเพิ่ม และเอกสารนี้ไม่ใช่ใบกำกับภาษี<br/>
    The seller is not VAT-registered. No VAT is charged, and this is not a tax invoice.
</div>
<!-- FOOTER (SHARED) -->
<div class="footer text-center">
    <div class="divider"></div>
    <p>
        <b>OtterNova &middot; ออทเทอร์โนวา</b> โดย <b>รตินันท์ ลีลางามวงศา</b><br/>
        ##/## ถนนสรงประภา แขวงดอนเมือง เขตดอนเมือง กรุงเทพมหานคร 10210 ประเทศไทย<br/>
        https://www.otternova.com &middot; support@otternova.com
    </p>
    <p class="text-right text-grey">
        template: <?= $e($doc['version']) ?>
    </p>
</div>
</body>
</html>
