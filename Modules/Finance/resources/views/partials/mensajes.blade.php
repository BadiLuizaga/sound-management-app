@if(session('success'))
    <div style="margin-bottom: 16px; padding: 12px 16px; border-radius: 10px; background: #ECFDF5; color: #065F46; font-weight: 600;">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div style="margin-bottom: 16px; padding: 12px 16px; border-radius: 10px; background: #FEF2F2; color: #991B1B; font-weight: 600;">{{ session('error') }}</div>
@endif
@if($errors->any())
    <div style="margin-bottom: 16px; padding: 12px 16px; border-radius: 10px; background: #FEF2F2; color: #991B1B;">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif
