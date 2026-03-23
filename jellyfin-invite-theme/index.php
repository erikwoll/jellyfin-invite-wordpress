<?php get_header(); ?>
<div class="site-wrap">
  <div class="entry-card">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
      <h1><?php the_title(); ?></h1>
      <?php the_content(); ?>
    <?php endwhile; else: ?>
      <h1>Jellyfin Invite Theme</h1>
      <p>Create a page with the shortcode <code>[jellyfin_invite_signup]</code>.</p>
    <?php endif; ?>
  </div>
</div>
<?php get_footer(); ?>
