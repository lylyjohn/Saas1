@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Dados do cliente (CRM)')

@section('conteudo')
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900">{{ $cliente->nome }}</h1>
        <p class="text-sm text-slate-500">Aniversário e consentimento para receber mensagens de marketing.</p>
    </div>

    <form method="POST" action="{{ route('atelier.crm.atualizar', $cliente->id) }}"
          class="max-w-xl space-y-4 rounded-md border border-slate-200 bg-white p-5">
        @csrf
        @method('PUT')

        <div>
            <label for="data_nascimento" class="mb-1 block text-xs font-medium text-slate-600">Data de nascimento</label>
            <input type="date" id="data_nascimento" name="data_nascimento"
                   value="{{ old('data_nascimento', $crm->data_nascimento?->format('Y-m-d')) }}"
                   class="w-full rounded-md border-slate-300 text-sm">
            @error('data_nascimento')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-start gap-2">
            <input type="checkbox" id="aceita_marketing" name="aceita_marketing" value="1"
                   class="mt-1 rounded border-slate-300" @checked(old('aceita_marketing', $crm->aceita_marketing ?? false))>
            <label for="aceita_marketing" class="text-sm text-slate-700">
                O cliente aceita receber mensagens de marketing (aniversário, promoções, campanhas).
                @if ($crm->consentimento_registado_em)
                    <span class="block text-xs text-slate-400">Consentimento registado em {{ $crm->consentimento_registado_em->format('d/m/Y H:i') }}</span>
                @endif
            </label>
        </div>

        <div>
            <label for="notas" class="mb-1 block text-xs font-medium text-slate-600">Notas</label>
            <textarea id="notas" name="notas" rows="3" class="w-full rounded-md border-slate-300 text-sm">{{ old('notas', $crm->notas) }}</textarea>
            @error('notas')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            Guardar
        </button>
    </form>
@endsection
