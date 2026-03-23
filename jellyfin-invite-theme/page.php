<?php get_header(); ?>
<div class="site-wrap">
  <div class="entry-card">
    <?php while (have_posts()) : the_post(); ?>
      <h1><?php the_title(); ?></h1>
      <?php the_content(); ?>
    <?php endwhile; ?>
  </div>
</div>
<?php get_footer(); ?>
