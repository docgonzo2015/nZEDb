<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateReleaseCommentsTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		if (!Schema::hasTable('release_comments')) {
			Schema::create('release_comments',
				function (Blueprint $table) {
					$table->increments('id');
					$table->integer('releases_id')
						->unsigned()
						->index('ix_releasecomment_releases_id')
						->comment('FK to releases.id');
					$table->string('text', 2000)->default('');
					$table->string('text_hash', 32)->default('');
					$table->string('username')->default('');
					$table->integer('user_id')->unsigned()->index('ix_releasecomment_userid');
					$table->dateTime('createddate')->nullable();
					$table->string('host', 15)->nullable();
					$table->boolean('shared')->default(0);
					$table->string('shareid', 40)->default('');
					$table->string('siteid', 40)->default('');
					$table->binary('nzb_guid', 16)->default('0               ');
					$table->unique(['text_hash', 'releases_id'],
						'ix_release_comments_hash_releases_id');
				});
		}
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::dropIfExists('release_comments');
	}

}
?>
