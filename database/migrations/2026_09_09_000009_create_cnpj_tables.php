<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cnaes', function (Blueprint $table) {
            $table->string('code', 7)->primary();
            $table->string('description');
        });

        Schema::create('cnae_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('keyword', 80);
            $table->string('cnae_code', 7);
            $table->timestamps();

            $table->unique(['keyword', 'cnae_code']);
            $table->index('keyword');
        });

        Schema::create('cnpj_municipalities', function (Blueprint $table) {
            $table->string('code', 4)->primary();
            $table->string('name');
            $table->index('name');
        });

        Schema::create('cnpj_establishments', function (Blueprint $table) {
            $table->string('cnpj', 14)->primary();
            $table->string('cnpj_basico', 8)->index();
            $table->boolean('is_headquarters')->default(true);
            $table->string('razao_social')->nullable();
            $table->string('nome_fantasia')->nullable();
            $table->string('situacao', 2)->index();
            $table->date('data_inicio')->nullable();
            $table->string('cnae_principal', 7)->index();
            $table->text('cnae_secundaria')->nullable();
            $table->string('uf', 2)->index();
            $table->string('municipio_code', 4)->index();
            $table->string('bairro')->nullable();
            $table->string('cep', 8)->nullable();
            $table->string('telefone1', 16)->nullable();
            $table->string('telefone2', 16)->nullable();
            $table->string('email')->nullable();
            $table->string('porte', 2)->nullable();

            $table->index(['cnae_principal', 'uf']);
            $table->index(['cnae_principal', 'municipio_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cnpj_establishments');
        Schema::dropIfExists('cnpj_municipalities');
        Schema::dropIfExists('cnae_mappings');
        Schema::dropIfExists('cnaes');
    }
};
