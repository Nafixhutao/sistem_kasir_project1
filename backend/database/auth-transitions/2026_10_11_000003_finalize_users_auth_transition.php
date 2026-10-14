<?php
use Illuminate\Database\Migrations\Migration;
use App\Support\AuthTransitionSchema;
return new class extends Migration {
    public function up(): void { AuthTransitionSchema::finalize(); }
    public function down(): void { AuthTransitionSchema::undoFinalize(); }
};
