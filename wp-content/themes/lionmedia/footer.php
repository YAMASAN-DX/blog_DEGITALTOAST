<?php
//AMPチェック(機能有効 &singlePage & ampParameter=1)
$myAmp = false;
if(get_option('fit_anp_check') == 'value2' && is_single() && @$_GET['amp'] === '1'){
    $myAmp = true;
}
?>


<!--l-footer-->
  <footer class="l-footer">
    <div class="container">
      <div class="pagetop u-txtShdw"><a class="pagetop__link" href="#top">Back to Top</a></div>
      
      <?php if(!$myAmp): ?>
      <?php if(is_active_sidebar('footer-left') || is_active_sidebar('footer-center') || is_active_sidebar('footer-right')): ?>
      <div class="widgetFoot">
        <div class="widgetFoot__contents">
        <?php if (is_active_sidebar('footer-left')) : ?>
          <?php dynamic_sidebar( 'footer-left' ); ?>
	    <?php endif; ?>
        </div>
        
        <div class="widgetFoot__contents">
        <?php if (is_active_sidebar('footer-center')) : ?>
          <?php dynamic_sidebar( 'footer-center' ); ?>
	    <?php endif; ?>
        </div>
        
        <div class="widgetFoot__contents">
        <?php if (is_active_sidebar('footer-right')) : ?>
          <?php dynamic_sidebar( 'footer-right' ); ?>
	    <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <div class="copySns <?php if(!is_active_sidebar('footer-left') && !is_active_sidebar('footer-center') && !is_active_sidebar('footer-right')): ?>copySns-noBorder<?php endif; ?>">
        <div class="copySns__copy">
          <?php if (get_option('fit_theme_copyright')): ?>
            <?php echo get_option('fit_theme_copyright'); ?>
          <?php else : ?>
            © Copyright <?php echo date( 'Y' ); ?> <a class="copySns__copyLink" href="<?php echo home_url(); ?>"><?php bloginfo( 'name' ); ?></a>.
          <?php endif; ?>
	      <span class="copySns__copyInfo<?php if (get_option('fit_theme_copyrightInfo')): ?> u-none<?php endif; ?>">
		    <?php bloginfo( 'name' ); ?> by <a class="copySns__copyLink" href="http://fit-jp.com/" target="_blank">FIT-Web Create</a>. Powered by <a class="copySns__copyLink" href="https://wordpress.org/" target="_blank">WordPress</a>.
          </span>
        </div>
      
        <?php $opt = get_option('fit_social'); ?>
        <?php if (isset($opt['FBFollowF']) && $opt['FBFollowF'] == '1' || isset($opt['twitterFollowF']) && $opt['twitterFollowF'] == '1' || isset($opt['instaFollowF']) && $opt['instaFollowF'] == '1' || isset($opt['googleFollowF']) && $opt['googleFollowF'] == '1' || isset($opt['rssFollowF']) && $opt['rssFollowF'] == '1'):	?>
        <ul class="copySns__list">
		  <?php if (isset($opt['FBFollowF']) && $opt['FBFollowF'] == '1' && $opt['FBPage'] != ''):?>
            <li class="copySns__listItem"><a class="copySns__listLink icon-facebook" href="https://www.facebook.com/<?php echo $opt['FBPage']; ?>"></a></li>
		  <?php endif; if (isset($opt['twitterFollowF']) && $opt['twitterFollowF'] == '1' && $opt['twitterId'] != '') : ?>
            <li class="copySns__listItem"><a class="copySns__listLink icon-twitter" href="https://twitter.com/<?php echo $opt['twitterId']; ?>"></a></li>
		  <?php endif; if (isset($opt['instaFollowF']) && $opt['instaFollowF'] == '1' && $opt['insta'] != '') : ?>
            <li class="copySns__listItem"><a class="copySns__listLink icon-instagram" href="http://instagram.com/<?php echo $opt['insta']; ?>"></a></li>
		  <?php endif; if (isset($opt['googleFollowF']) && $opt['googleFollowF'] == '1' && $opt['googleUrl'] != '') : ?>
            <li class="copySns__listItem"><a class="copySns__listLink icon-google" href="https://plus.google.com/+<?php echo $opt['googleUrl']; ?>"></a></li>
          <?php endif; if (isset($opt['rssFollowF']) && $opt['rssFollowF'] == '1'): ?>
            <?php $optRssUrl = $opt['rssUrl']; if (!empty($optRssUrl)) : ?>
              <li class="copySns__listItem"><a class="copySns__listLink icon-rss" href="<?php echo $opt['rssUrl']; ?>"></a></li>
            <?php else : ?>
              <li class="copySns__listItem"><a class="copySns__listLink icon-rss" href="<?php bloginfo('rss2_url'); ?>"></a></li>
			<?php endif; ?>
		  <?php endif; ?>
        </ul>
        <?php endif; ?>
      
      </div>

    </div>     
  </footer>
  <!-- /l-footer -->

  <?php if(!$myAmp): ?>
    <?php wp_footer(); ?>
  <?php endif; ?>

<!--構造化データ-->
<?php if ( is_single()) : ?>
  <?php
     $thumbnail_id = get_post_thumbnail_id($post);
     $imageobject = wp_get_attachment_image_src( $thumbnail_id, 'full' );
  ?>
<script type="application/ld+json">
{
  "@context": "http://schema.org",
  "@type": "BlogPosting",
  "mainEntityOfPage":{
    "@type":"WebPage",
    "@id":"<?php the_permalink(); ?>"
  },
  "headline":"<?php the_title(); ?>",
  "image": [
    "<?php echo $imageobject[0]; ?>"
  ],
  "datePublished": "<?php echo get_date_from_gmt(get_post_time('c', true), 'c');?>",
  "dateModified": "<?php echo get_date_from_gmt(get_post_modified_time('c', true), 'c');?>",
  "author": {
    "@type": "Person",
    "name": "デジトー管理人"
  },
  "description":  "<?php echo get_the_excerpt(); ?>"
}
</script>
<?php endif; ?>

<script type="text/javascript">
    amzn_assoc_ad_type = "link_enhancement_widget";
    amzn_assoc_tracking_id = "degitaltoas04-22";
    amzn_assoc_linkid = "d42c708e74eb5075eff4efa27724e980";
    amzn_assoc_placement = "";
    amzn_assoc_marketplace = "amazon";
    amzn_assoc_region = "JP";
</script>
<script src="//ws-fe.amazon-adsystem.com/widgets/q?ServiceVersion=20070822&Operation=GetScript&ID=OneJS&WS=1&MarketPlace=JP"></script>
</body>
</html>