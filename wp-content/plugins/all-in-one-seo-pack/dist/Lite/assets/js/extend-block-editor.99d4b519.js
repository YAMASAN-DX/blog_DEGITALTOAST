import{x as u,y as h,z as x}from"./app-core.36551fe5.js";import{h as s}from"./utils.a8892d76.js";import{A as m}from"./icon.47e79186.js";import{_ as $}from"./vendor-other.9c03483c.js";import"./vendor-vue-ui.235cc81c.js";import"./vendor-lodash.9c3bb1fa.js";const{addFilter:A}=window.wp.hooks,{BlockControls:p}=window.wp.blockEditor,{Button:l,ToolbarGroup:b,ToolbarButton:B}=window.wp.components,{Fragment:I,createRoot:y,flushSync:S,render:E,unmountComponentAtNode:v}=window.wp.element,{createHigherOrderComponent:G}=window.wp.compose,{select:d,useSelect:T}=window.wp.data,w="all-in-one-seo-pack",g={generateWithAI:$("Generate with AI",w),editWithAI:$("Edit with AI",w)};let k=!1;const _=(n,t)=>{if(y&&S){const e=y(t);return S(()=>{e.render(n)}),()=>e.unmount()}return E(n,t),()=>v(t)},f=(n,t={})=>{window.aioseoBus.$emit("do-post-settings-main-tab-change",{name:"aiContent"}),n.classList.add("is-busy"),n.disabled=!0;const e=x(),o=u();setTimeout(()=>{o.initiator=t?.initiator,(!o.initiator||!o.initiator.slug)&&o.resetInitiator(),e.isModalOpened="image-generator",n.classList.remove("is-busy"),n.disabled=!1},500)},D=()=>{u().extend.imageBlockToolbar&&(k||(A("editor.BlockEdit","aioseo/extend-image-block-toolbar",G(t=>e=>{const o=e.name==="core/image"&&e.attributes?.url,i=T(r=>!o||!e.attributes?.id?null:r("core").getEntityRecord("postType","attachment",e.attributes.id)||null,[`media-${e.attributes.id}`]);return o?s`
				<${I}>
					<${p}>
						<${b}>
							<${B}
								icon=${m}
								iconSize=${24}
								label=${g.editWithAI}
								onClick=${r=>{f(r.currentTarget,{initiator:{slug:"image-block-toolbar",wpMedia:i}})}}
								style=${{maxHeight:"90%",alignSelf:"center",padding:"0"}}
							/>
						</${b}>
					</${p}>

					<${t} ...${e} />
				</${I}>`:s`<${t} ...${e} />`},"extendImageBlockToolbar")),k=!0))},P=()=>{if(!u().extend.imageBlockPlaceholder)return;const t=d("core/block-editor").getSelectedBlock();!t||t.name!=="core/image"||t.attributes?.url||setTimeout(()=>{const o=h().getElementById(`block-${t.clientId}`),i=o?.querySelector(".components-form-file-upload");if(!i||o?.querySelector(".aioseo-ai-image-generator-btn"))return;const r=document.createElement("div"),a=_(s`
				<${l}
					className=${"aioseo-ai-image-generator-btn"}
					variant=${"secondary"}
					icon=${m}
					iconSize=${"20"}
					__next40pxDefaultSize=${!0}
				>
					${g.generateWithAI}
				</${l}>`,r),c=r.firstChild?.cloneNode(!0);c&&(i.after(c),c.addEventListener("click",()=>{f(c,{initiator:{slug:"image-block-placeholder"}})})),a(),r.remove()})},F=()=>{if(!u().extend.featuredImageButton||d("core/edit-post").getActiveGeneralSidebarName()!=="edit-post/document")return;if(d("core/editor").getEditedPostAttribute("featured_media")){document.querySelector(".aioseo-ai-image-generator-btn-featured-image")?.remove();return}setTimeout(()=>{const e=document.querySelector(".editor-post-featured-image__container"),o=e?.querySelector("button");if(!o||e?.querySelector(".aioseo-ai-image-generator-btn-featured-image"))return;e.style.display="flex",e.style.gap="8px";const i=document.createElement("div"),r=_(s`
				<${l}
					className=${"aioseo-ai-image-generator-btn-featured-image"}
					variant=${"secondary"}
					icon=${m}
					iconSize=${"20"}
					__next40pxDefaultSize=${!0}
					title=${g.generateWithAI}
				/>`,i),a=i.firstChild?.cloneNode(!0);a&&(o.after(a),a.addEventListener("click",()=>{f(a,{initiator:{slug:"featured-image-btn"}})})),r(),i.remove()})};export{F as extendFeaturedImageButton,P as extendImageBlockPlaceholder,D as extendImageBlockToolbar};
