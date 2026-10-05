<dialog id="workflow-help" class="workflow-help" aria-labelledby="workflow-help-title">
    <div class="p-6 sm:p-8">
        <div class="flex items-start justify-between gap-4"><div><p class="eyebrow">A quick guide to LRS</p><h2 id="workflow-help-title" class="mt-2 text-2xl">One request. Five clear stages.</h2></div><button type="button" data-close-dialog class="btn btn-secondary !px-3" aria-label="Close guide"><x-icon name="x" /></button></div>
        <p class="mt-4 text-sm leading-7 text-slate-600">LRS helps your team turn a customer's shipping request into a price and a confirmed vendor booking.</p>
        <ol class="workflow-explainer mt-6">
            @foreach([
                ['Shipment details','Check what is moving, where it goes and when it is needed.'],
                ['Ask vendors','Ask suitable logistics providers for their prices.'],
                ['Compare prices','Review the same services and choose a vendor offer.'],
                ['Customer quote','Add your markup, review the customer price, then approve and send.'],
                ['Confirm & book','Record the customer decision, reconfirm the vendor and hand over to operations. Record actual booking evidence separately.'],
            ] as [$label,$description])
            <li><span class="journey-number" aria-hidden="true">{{ $loop->iteration }}</span><div><h3 class="text-sm">{{ $label }}</h3><p class="mt-1 text-sm leading-6 text-slate-600">{{ $description }}</p></div></li>
            @endforeach
        </ol>
        <details class="mt-6 border-t border-slate-200 pt-4"><summary class="cursor-pointer text-sm font-medium">What do these words mean?</summary><dl class="mt-4 space-y-3 text-sm leading-6"><div><dt class="font-medium">Inquiry</dt><dd>A customer request for one shipment.</dd></div><div><dt class="font-medium">Client / customer</dt><dd>The company asking you to arrange shipping.</dd></div><div><dt class="font-medium">Vendor</dt><dd>A logistics provider that supplies a service and price.</dd></div><div><dt class="font-medium">RFQ</dt><dd>A request asking a vendor for a quotation.</dd></div><div><dt class="font-medium">Markup</dt><dd>The percentage you add to eligible vendor cost to set your selling price.</dd></div><div><dt class="font-medium">LCL / FCL</dt><dd>LCL shares container space. FCL uses a full container.</dd></div><div><dt class="font-medium">CBM</dt><dd>Cargo volume in cubic metres.</dd></div><div><dt class="font-medium">Handoff</dt><dd>The checked shipment instructions passed to your operations team.</dd></div></dl></details>
        <p class="mt-6 rounded-xl bg-slate-50 p-4 text-xs leading-6">Saving a draft, approving it, sending an email and confirming a booking are separate actions. Follow the next-step card inside each inquiry.</p>
    </div>
</dialog>
