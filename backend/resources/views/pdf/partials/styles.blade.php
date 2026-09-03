{{-- Shared print styles for every generated document. DomPDF needs plain CSS
     with no custom properties, so values are literal. --}}
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1f2937; font-size: 12px; margin: 0; }
    .header { border-bottom: 3px solid #29A9E1; padding-bottom: 12px; margin-bottom: 18px; }
    .brand { font-size: 20px; font-weight: bold; color: #1E3C8C; }
    .doc-title { font-size: 16px; font-weight: bold; text-transform: uppercase; color: #111827; margin-top: 4px; }
    .muted { color: #6b7280; }
    .meta-table { width: 100%; margin-bottom: 18px; }
    .meta-table td { vertical-align: top; padding: 2px 0; }
    .label { color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.items th { background: #1E3C8C; color: #fff; text-align: left; padding: 7px 8px; font-size: 10px; text-transform: uppercase; }
    table.items td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; }
    table.items tr:nth-child(even) td { background: #f9fafb; }
    .totals { margin-top: 10px; text-align: right; font-weight: bold; }
    .sign-box { margin-top: 36px; }
    .sign-line { border-top: 1px solid #9ca3af; width: 240px; margin-top: 40px; padding-top: 4px; font-size: 10px; }
    .sig-img { max-height: 70px; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    .pill { display: inline-block; background: #eff8fe; color: #1c4f8f; border: 1px solid #7ccbf5; border-radius: 9999px; padding: 2px 10px; font-size: 10px; }

    /* Section headings for multi-block reports. */
    .section-title { font-size: 13px; font-weight: bold; color: #1E3C8C; margin: 22px 0 2px; }
    .section-note { font-size: 10px; color: #6b7280; margin-bottom: 4px; }

    /* Discrepancy blocks. The orange highlight the spec calls for, carried
       through from the on-screen table to the printed report. */
    .adjust-title { color: #B45309; }
    table.items.adjust th { background: #B45309; }
    table.items.adjust td { background: #FFF7ED; border-bottom: 1px solid #FDBA74; }
    table.items.adjust tr:nth-child(even) td { background: #FFEDD5; }
    .adjust-tag { display: inline-block; background: #FFEDD5; color: #9A3412; border: 1px solid #FDBA74; border-radius: 3px; padding: 1px 5px; font-size: 9px; text-transform: uppercase; }
    .strike { text-decoration: line-through; color: #9A3412; }
    .neg { color: #b91c1c; font-weight: bold; }
    .pos { color: #047857; font-weight: bold; }
    .zero { color: #6b7280; }
</style>
