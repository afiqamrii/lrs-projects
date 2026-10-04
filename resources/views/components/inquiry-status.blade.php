@props(['status'])
<span class="badge {{ match($status) {'ready_for_sourcing'=>'badge-active','needs_client_information','on_hold'=>'badge-warning','needs_review'=>'tint-blue',default=>'badge-inactive'} }}"><span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>{{ \App\Models\Inquiry::STATUSES[$status] }}</span>
